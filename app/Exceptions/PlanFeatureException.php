<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Billing\Plan;
use RuntimeException;

/**
 * Raised when a team's plan does not include a capability it just asked for.
 *
 * Separate from PlanLimitException because the answer is different: a limit is
 * "you have used all of them", a feature is "your plan never had this".
 *
 * The message names the cheapest plan that does include it, and deliberately
 * not the one the team is on. The plan they are on is the one that just said
 * no; repeating it back tells them what they found out a second ago. Naming
 * the plan that does is what turns the refusal into a next step.
 */
class PlanFeatureException extends RuntimeException
{
    public static function required(string $feature): self
    {
        $upgrade = self::cheapestPlanWith($feature);

        if (! $upgrade instanceof Plan) {
            return new self(__('The :feature feature is not part of any plan.', [
                'feature' => $feature,
            ]));
        }

        return new self(__('The :feature feature comes with the :plan plan.', [
            'feature' => $feature,
            'plan' => $upgrade->name,
        ]));
    }

    /**
     * The least expensive way to get the capability, if there is one.
     *
     * Cheapest rather than first, because the order of the plans in config is
     * a layout decision and this is a price quote. Every plan in the catalog
     * is considered, including a free one: this is an answer to "which plan
     * has this", not to which plans are currently purchasable.
     */
    private static function cheapestPlanWith(string $feature): ?Plan
    {
        $plans = array_values(array_filter(
            Plan::all(),
            fn (Plan $plan): bool => $plan->hasFeature($feature),
        ));

        usort($plans, fn (Plan $a, Plan $b): int => $a->price <=> $b->price);

        return $plans[0] ?? null;
    }
}
