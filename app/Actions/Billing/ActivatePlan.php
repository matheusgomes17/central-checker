<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\Subscriptions;

/**
 * Activates, extends, or switches a team's prepaid subscription once a Pix
 * plan payment is confirmed.
 *
 * There is always at most one current subscription row per team — the one
 * `Subscriptions::active()` returns. Paying for the plan already active
 * renews it by pushing `current_period_end` forward; paying for a different
 * plan switches it in place, resetting the period instead of stacking a
 * second row. Runs from the payment webhook, which has no tenant in
 * context, so `team_id` is set explicitly rather than relying on the
 * tenancy scope.
 */
class ActivatePlan
{
    public function __construct(private readonly Subscriptions $subscriptions) {}

    public function handle(Team $team, Plan $plan): PlanSubscription
    {
        $active = $this->subscriptions->active($team);

        if ($active instanceof PlanSubscription) {
            if ($active->plan === $plan->key) {
                $active->update([
                    'current_period_end' => $active->current_period_end->addDays($plan->periodDays),
                    'canceled_at' => null,
                ]);
            } else {
                $active->update([
                    'plan' => $plan->key,
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays($plan->periodDays),
                    'canceled_at' => null,
                ]);
            }

            return $active->refresh();
        }

        $subscription = new PlanSubscription;

        $subscription->forceFill([
            'team_id' => $team->getKey(),
            'plan' => $plan->key,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($plan->periodDays),
            'canceled_at' => null,
        ])->save();

        return $subscription;
    }
}
