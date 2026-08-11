<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\PlanSubscription;
use App\Models\Team;

/**
 * Resolves a team's current prepaid subscription and derives its billing state.
 */
final class Subscriptions
{
    /**
     * The subscription whose paid period has not ended yet, if any.
     */
    public function active(Team $team): ?PlanSubscription
    {
        return PlanSubscription::query()
            ->where('team_id', $team->getKey())
            ->where('current_period_end', '>', now())
            ->latest('current_period_end')
            ->first();
    }

    public function everSubscribed(Team $team): bool
    {
        return PlanSubscription::query()->where('team_id', $team->getKey())->exists();
    }

    public function statusFor(Team $team): SubscriptionStatus
    {
        $active = $this->active($team);

        if ($active !== null) {
            return $active->isExpiring() ? SubscriptionStatus::Expiring : SubscriptionStatus::Active;
        }

        if ($team->trial_ends_at?->isFuture() === true) {
            return SubscriptionStatus::Trialing;
        }

        return $this->everSubscribed($team) ? SubscriptionStatus::Expired : SubscriptionStatus::None;
    }
}
