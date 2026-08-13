<?php

declare(strict_types=1);

namespace Modules\Wallet\Actions;

use App\Support\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\HoldNotActiveException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Models\WalletTransaction;

/**
 * Settles a hold into a charge: the reserved money finally moves.
 *
 * Locks both the hold and the wallet so a confirm cannot race a release of the
 * same hold, nor read a balance another charge is mid-write on. A hold that is
 * no longer Active — already released or already settled — is refused rather
 * than double-charged.
 */
class ConfirmHold
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(WalletHold $hold, string $description, ?Model $reference = null): WalletTransaction
    {
        return $this->tenants->forTenant($hold->team, fn (): WalletTransaction => DB::transaction(function () use ($hold, $description, $reference): WalletTransaction {
            $locked = WalletHold::query()->whereKey($hold->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== WalletHoldStatus::Active) {
                throw HoldNotActiveException::forId($locked->id);
            }

            $wallet = Wallet::query()->whereKey($locked->wallet_id)->lockForUpdate()->firstOrFail();
            $newBalance = $wallet->balance_cents - $locked->amount_cents;

            $transaction = $wallet->transactions()->create([
                'type' => WalletTransactionType::Charge,
                'amount_cents' => -$locked->amount_cents,
                'balance_after_cents' => $newBalance,
                'description' => $description,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);

            $wallet->forceFill(['balance_cents' => $newBalance])->save();
            $locked->forceFill(['status' => WalletHoldStatus::Settled, 'settled_at' => now()])->save();

            return $transaction;
        }));
    }
}
