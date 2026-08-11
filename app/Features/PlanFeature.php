<?php

declare(strict_types=1);

namespace App\Features;

use App\Support\Billing\Plan;
use Laravel\Pennant\Feature;

/**
 * Registers one Pennant feature per capability a plan can grant.
 *
 * Each one resolves to false and nothing else. The flag is not the answer to
 * "may this team use the capability" — it is only the grant support can hand
 * out on top of the plan, which is the story Pennant was brought in for: a
 * customer promised a capability their plan does not include, without anyone
 * editing the plan config for one account.
 *
 * Deriving the answer from the plan here would be wrong, not merely redundant.
 * Pennant's database store writes the first resolution down and returns it
 * forever after, so a team refused while free would stay refused after paying,
 * and a team allowed while paying would stay allowed after cancelling. The
 * plan changes; a stored flag does not. Callers ask the plan and the flag
 * separately, at request time — see EnsurePlanFeature.
 *
 * Resolution is deliberate about which team it asks about. Pennant scopes to
 * whatever object it is given; here that is always the team, because plans
 * belong to teams and a person can be in several.
 */
class PlanFeature
{
    public static function registerAll(): void
    {
        foreach (self::names() as $name) {
            Feature::define($name, fn (): bool => false);
        }
    }

    /**
     * Every capability named by any plan.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (Plan::all() as $plan) {
            foreach ($plan->features as $feature) {
                $names[$feature] = true;
            }
        }

        return array_keys($names);
    }
}
