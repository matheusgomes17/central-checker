<?php

declare(strict_types=1);

namespace Modules\Carteira\Models;

use App\Concerns\BelongsToTenant;
use App\Models\Team;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Carteira\Database\Factories\WalletFactory;
use Modules\Carteira\Enums\WalletHoldStatus;

/**
 * A team's balance, in cents.
 *
 * One row per team (enforced by a unique index), created lazily the first time
 * money moves. Scoped by team like every domain model; forTeam() resolves it
 * from any context — a queued job or the operator panel — by filtering on the
 * explicit team_id with tenancy suspended.
 *
 * @property int $id
 * @property int $team_id
 * @property int $balance_cents
 */
#[Fillable(['balance_cents'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use BelongsToTenant, HasFactory;

    public static function forTeam(Team $team): self
    {
        return self::withoutTenancy(
            fn (): self => self::query()->where('team_id', $team->getKey())->first()
                ?? self::withoutEvents(function () use ($team): self {
                    // withoutEvents evita o hook `creating` da trait, que
                    // preencheria team_id a partir do tenant ambiente — errado
                    // aqui, onde a tenancy está suspensa de propósito.
                    $wallet = new self;
                    $wallet->forceFill(['team_id' => $team->getKey(), 'balance_cents' => 0]);
                    $wallet->save();

                    return $wallet;
                }),
        );
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * @return HasMany<WalletHold, $this>
     */
    public function holds(): HasMany
    {
        return $this->hasMany(WalletHold::class);
    }

    /**
     * Balance minus everything currently reserved.
     */
    public function available(): int
    {
        $reserved = (int) $this->holds()
            ->where('status', WalletHoldStatus::Active->value)
            ->sum('amount_cents');

        return $this->balance_cents - $reserved;
    }
}
