<?php

declare(strict_types=1);

namespace Modules\Wallet\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Database\Factories\WalletHoldFactory;
use Modules\Wallet\Enums\WalletHoldStatus;
use Override;

/**
 * Money set aside for an in-flight operation.
 *
 * A hold reduces the available balance without moving it: the charge only lands
 * if the work succeeds (settled), otherwise the hold is released. It exists so
 * two concurrent operations cannot both spend the same balance while neither
 * has settled yet. An orphaned hold is swept by wallet:expire-holds.
 *
 * @property int $id
 * @property int $team_id
 * @property int $wallet_id
 * @property int $amount_cents
 * @property WalletHoldStatus $status
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $settled_at
 * @property \Illuminate\Support\Carbon|null $released_at
 */
#[Fillable(['wallet_id', 'amount_cents', 'status', 'expires_at', 'settled_at', 'released_at'])]
class WalletHold extends Model
{
    /** @use HasFactory<WalletHoldFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => WalletHoldStatus::class,
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
