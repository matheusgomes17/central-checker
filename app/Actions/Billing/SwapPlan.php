<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Exceptions\PlanSwapException;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;

/**
 * Moves a subscribed team to another plan, prorated.
 *
 * Proration is Stripe's default and we keep it: `noProrate()` is deliberately
 * not called. Going up bills the difference, going down leaves a credit on the
 * next invoice — one code path for both directions, which is what keeps the
 * screen able to explain what will happen.
 *
 * Does not authorize. The caller does, as everywhere else in app/Actions.
 */
class SwapPlan
{
    public function handle(Team $team, Plan $plan): void
    {
        $subscription = $team->subscription(TeamBilling::SUBSCRIPTION);

        if (! $subscription instanceof \Laravel\Cashier\Subscription) {
            throw PlanSwapException::notSubscribed();
        }

        if (TeamBilling::for($team)->plan()->key === $plan->key) {
            throw PlanSwapException::samePlan($plan);
        }

        if ($plan->priceId === null) {
            throw PlanSwapException::notPurchasable($plan);
        }

        $subscription->swap($plan->priceId);
    }
}
