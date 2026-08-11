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
 * covers — and Cashier's own vocabulary is subtle enough that they would
 * disagree.
 *
 * The subtleties worth stating, because they are where billing bugs live:
 *
 * - `subscribed()` stays true through the cancellation grace period. Someone
 *   who cancelled today keeps what they paid for until the period ends.
 * - `subscribed()` is false while a payment is `past_due` or `incomplete`.
 *   Stripe is still retrying; the account is not cancelled, but it is not in
 *   good standing either.
 * - a trial has no subscription record at all when it is card-free, so trial
 *   state has to be read from the team, not from a subscription.
 */
final readonly class TeamBilling
{
    public const string SUBSCRIPTION = 'default';

    public function __construct(private Team $team) {}

    public static function for(Team $team): self
    {
        return new self($team);
    }

    /**
     * The plan the team is entitled to right now.
     *
     * A team on trial gets the plan under trial; one whose payment is
     * failing keeps its plan while Stripe retries, because pulling access on
     * the first failed charge punishes an expired card.
     */
    public function plan(): Plan
    {
        // A assinatura encerrada continua na tabela, com o preço que teve. Ler
        // o preço sem olhar o acesso deixaria um time cancelado no plano pago
        // para sempre.
        if (! $this->hasAccess()) {
            return Plan::default();
        }

        $price = $this->team->subscription(self::SUBSCRIPTION)?->stripe_price;

        return Plan::forPriceId($price) ?? Plan::default();
    }

    public function onTrial(): bool
    {
        // O onTrial() do Cashier só consulta o teste sem cartão quando é
        // chamado sem nenhum argumento — informar o tipo desliga essa
        // checagem. Como o teste daqui é sem cartão, ele não tem assinatura
        // para consultar, e os dois casos precisam ser perguntados.
        if ($this->team->onGenericTrial()) {
            return true;
        }

        return $this->team->onTrial(self::SUBSCRIPTION);
    }

    public function trialEndsAt(): ?CarbonInterface
    {
        return $this->team->trial_ends_at;
    }

    /**
     * Whether the team is paying and in good standing.
     */
    public function subscribed(): bool
    {
        return $this->team->subscribed(self::SUBSCRIPTION);
    }

    /**
     * Cancelled but still inside the period already paid for.
     */
    public function onGracePeriod(): bool
    {
        return $this->team->subscription(self::SUBSCRIPTION)?->onGracePeriod() ?? false;
    }

    /**
     * Stripe is retrying a failed charge.
     *
     * Distinct from cancelled: nobody chose this, and the way out is a new
     * card rather than a new subscription.
     */
    public function paymentFailing(): bool
    {
        $subscription = $this->team->subscription(self::SUBSCRIPTION);

        return $subscription instanceof \Laravel\Cashier\Subscription && ($subscription->pastDue() || $subscription->incomplete());
    }

    /**
     * The subscription is over and the paid period with it.
     */
    public function ended(): bool
    {
        return $this->team->subscription(self::SUBSCRIPTION)?->ended() ?? false;
    }

    /**
     * Whether the team may use paid capability at all.
     *
     * Trial and grace period both count; a failing payment counts too, for as
     * long as Stripe keeps retrying. What does not count is having never
     * subscribed, or having reached the end of a cancelled period.
     */
    public function hasAccess(): bool
    {
        if ($this->subscribed()) {
            return true;
        }
        if ($this->onTrial()) {
            return true;
        }

        return $this->paymentFailing();
    }

    /**
     * Whether the team may still change things.
     *
     * Read-only is the end of the line described by the plan: payment fails,
     * Stripe retries, the retries run out, and the subscription ends. What is
     * left is an account that can be read and paid for, but not added to.
     *
     * A team that never subscribed is *not* here. It is on the free plan, with
     * the free plan's limits, doing exactly what it is entitled to. Suspending
     * it would turn "did not buy" into "did something wrong".
     */
    public function isReadOnly(): bool
    {
        return $this->status() === SubscriptionStatus::Ended;
    }

    public function status(): SubscriptionStatus
    {
        return match (true) {
            $this->onTrial() => SubscriptionStatus::Trialing,
            $this->paymentFailing() => SubscriptionStatus::PastDue,
            $this->onGracePeriod() => SubscriptionStatus::Cancelling,
            $this->subscribed() => SubscriptionStatus::Active,
            $this->ended() => SubscriptionStatus::Ended,
            default => SubscriptionStatus::None,
        };
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
