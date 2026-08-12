<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\UserCheckOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CheckerApi\Models\Check;
use Override;

/**
 * A per-user override on top of a check's default access and price — the
 * three levers a team can pull for one member: block them outright, grant
 * access regardless of plan, or charge them a custom credit price.
 *
 * Scoped by team like every domain model. `for()` mirrors
 * `Wallet::forTeam`: it resolves the override with tenancy suspended and an
 * explicit team_id, so it works from any context (a queued job, the
 * operator panel) rather than only from inside the team's own request.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int $check_id
 * @property bool $blocked
 * @property bool $granted
 * @property int|null $price_cents
 */
#[Fillable(['user_id', 'check_id', 'blocked', 'granted', 'price_cents'])]
class UserCheckOverride extends Model
{
    /** @use HasFactory<UserCheckOverrideFactory> */
    use BelongsToTenant, HasFactory;

    public static function for(Team $team, User $user, Check $check): ?self
    {
        return self::withoutTenancy(
            fn (): ?self => self::query()
                ->where('team_id', $team->getKey())
                ->where('user_id', $user->getKey())
                ->where('check_id', $check->getKey())
                ->first(),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Check, $this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'blocked' => 'boolean',
            'granted' => 'boolean',
        ];
    }
}
