<?php

declare(strict_types=1);

namespace Modules\Wallet\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Wallet\Database\Factories\WalletTransactionFactory;
use Modules\Wallet\Enums\WalletTransactionType;
use Override;

/**
 * One immutable line in a wallet's ledger.
 *
 * Never updated after it is written — a correction is another line, not an edit.
 * balance_after_cents freezes the running balance at the moment it was written,
 * so the ledger reconciles against the wallet's cached balance.
 *
 * @property int $id
 * @property int $team_id
 * @property int $wallet_id
 * @property WalletTransactionType $type
 * @property int $amount_cents
 * @property int $balance_after_cents
 * @property string $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 */
#[Fillable(['wallet_id', 'type', 'amount_cents', 'balance_after_cents', 'description', 'reference_type', 'reference_id'])]
class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
        ];
    }
}
