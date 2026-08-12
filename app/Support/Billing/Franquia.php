<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\PlanCheck;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\TenantManager;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;

/**
 * Resolves how much of a team's plan franquia a check has used this period.
 *
 * The period aligns with the paid subscription cycle — `current_period_start`
 * of the team's active `PlanSubscription` — falling back to the calendar
 * month for teams without an active subscription (free plan, trial).
 *
 * Usage is derived from successful `CheckExecution` history rather than kept
 * in a separate counter, so every franquia is countable without a migration
 * to backfill a counter that did not exist yet. Only the `Success` outcome
 * consumes franquia: `NoData` is a legitimate answer and `Failed` feeds the
 * circuit breaker, neither is a use of the check the team should pay for.
 *
 * Success alone is not enough, though: `used` also requires
 * `consumed_franquia`, set per execution by `ExecutarCheck` only on the
 * franquia-free branch. A granted (comp) override or a credit charge is
 * outside the plan by design and must not eat into it.
 */
final class Franquia
{
    /**
     * The franquia state for this team and check, or null when the team's
     * plan does not include the check at all.
     */
    public function for(Team $team, Check $check): ?FranquiaStatus
    {
        $plan = TeamBilling::for($team)->plan();

        $planCheck = PlanCheck::query()
            ->where('plan', $plan->key)
            ->where('check_id', $check->getKey())
            ->first();

        if (! $planCheck instanceof PlanCheck) {
            return null;
        }

        $active = resolve(Subscriptions::class)->active($team);
        $periodStart = $active instanceof PlanSubscription
            ? $active->current_period_start
            : now()->startOfMonth();

        $used = resolve(TenantManager::class)->forTenant(
            $team,
            fn (): int => CheckExecution::query()
                ->where('check_id', $check->getKey())
                ->where('outcome', ProviderOutcome::Success->value)
                ->where('consumed_franquia', true)
                ->where('created_at', '>=', $periodStart)
                ->count(),
        );

        return new FranquiaStatus($planCheck->quota, $used);
    }
}
