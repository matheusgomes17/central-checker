<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * The in-house prepaid subscription that replaces Cashier for plan billing.
 *
 * Resolved by the payment webhook with no tenant in context, exactly like
 * Cashier's `subscriptions` table — see `App\Support\TenantManager::UNSCOPED_MODELS`
 * for the allowlist entry and its justification.
 *
 * @property int $id
 * @property int $team_id
 * @property string $plan
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property Carbon|null $canceled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['plan', 'current_period_start', 'current_period_end', 'canceled_at'])]
class PlanSubscription extends Model
{
    /** @use HasFactory<PlanSubscriptionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isActive(): bool
    {
        return $this->current_period_end->isFuture();
    }

    public function isExpiring(): bool
    {
        return $this->isActive() && $this->canceled_at !== null;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }
}
