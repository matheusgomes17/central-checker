<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;

/**
 * Answers what a team is entitled to, right now.
 *
 * Everything else in the application asks this and nothing else: which plan,
 * which limits, whether the account is in good standing. Scattering those
 * questions would mean each caller inventing its own idea of what "subscribed"
 * covers.
 *
 * The subscription is read from our own in-house `PlanSubscription` table —
 * prepaid via Pix, resolved through `Subscriptions` — and not from Cashier
 * anymore. The subtleties worth stating, because they are where billing bugs
 * live:
 *
 * - `subscribed()` is true whenever there is a subscription whose paid period
 *   has not ended yet. A cancellation does not end the period already paid
 *   for: a subscription marked not to renew but still inside its period
 *   counts as subscribed, and shows up as `Expiring`.
 * - a trial has no subscription record at all — it is read from the team's
 *   `trial_ends_at` — and grants access to the free plan, not to a paid one.
 *   The prepaid trial exists to try the product; buying a paid plan is a
 *   purchase, not something a trial stands in for.
 * - there is no payment-failing state. Pix has no gateway retrying a failed
 *   charge: a period either still has time left or it does not.
 */
final readonly class TeamBilling
{
    public function __construct(private Team $team) {}

    public static function for(Team $team): self
    {
        return new self($team);
    }

    /**
     * The plan the team is entitled to right now.
     *
     * A team on trial is on the free plan: the trial is for trying the
     * product, not a stand-in for a paid plan nobody bought.
     */
    public function plan(): Plan
    {
        $active = resolve(Subscriptions::class)->active($this->team);

        return $active !== null ? (Plan::tryFind($active->plan) ?? Plan::default()) : Plan::default();
    }

    public function onTrial(): bool
    {
        return ! $this->subscribed() && ($this->team->trial_ends_at?->isFuture() === true);
    }

    public function trialEndsAt(): ?CarbonInterface
    {
        return $this->team->trial_ends_at;
    }

    /**
     * Whether the team has a paid period that has not ended yet.
     */
    public function subscribed(): bool
    {
        return resolve(Subscriptions::class)->active($this->team) !== null;
    }

    /**
     * Marked not to renew but still inside the period already paid for.
     */
    public function onGracePeriod(): bool
    {
        return resolve(Subscriptions::class)->active($this->team)?->isExpiring() ?? false;
    }

    /**
     * There is no payment-failing state in the prepaid model: Pix has no
     * gateway retrying a failed charge, so there is nothing to be "failing".
     */
    public function paymentFailing(): bool
    {
        return false;
    }

    /**
     * The team once subscribed, but no paid period is active now.
     */
    public function ended(): bool
    {
        $subscriptions = resolve(Subscriptions::class);

        return $subscriptions->active($this->team) === null
            && $subscriptions->everSubscribed($this->team)
            && ! $this->onTrial();
    }

    /**
     * Whether the team may use paid capability at all.
     *
     * Trial and grace period both count. What does not count is having never
     * subscribed, or having reached the end of a period nobody renewed.
     */
    public function hasAccess(): bool
    {
        if ($this->subscribed()) {
            return true;
        }

        return $this->onTrial();
    }

    /**
     * There is no read-only suspension in the prepaid model: a period ending
     * drops the team to the free plan with the free plan's ordinary access,
     * it does not lock the account. Keep this `false` — do not "fix" it back
     * into a suspension state.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function status(): SubscriptionStatus
    {
        return resolve(Subscriptions::class)->statusFor($this->team);
    }

    /**
     * How many of a resource the team is allowed, and how many it uses.
     *
     * @return array{limit: int|null, used: int, remaining: int|null}
     */
    public function usage(string $resource): array
    {
        $limit = $this->plan()->limit($resource);
        $used = $this->countOf($resource);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }

    public function withinLimit(string $resource, int $adding = 1): bool
    {
        $limit = $this->plan()->limit($resource);

        if ($limit === null) {
            return true;
        }

        return ($this->countOf($resource) + $adding) <= $limit;
    }

    public function trialDays(): int
    {
        return Config::integer('billing.trial_days');
    }

    /**
     * Current consumption of a metered resource.
     *
     * The registry answers, and it refuses a resource nobody registered. That
     * refusal is the point: a plan limiting something the product cannot count
     * is a ceiling that never applies.
     */
    private function countOf(string $resource): int
    {
        return resolve(ResourceCounters::class)->countFor($resource, $this->team);
    }
}
