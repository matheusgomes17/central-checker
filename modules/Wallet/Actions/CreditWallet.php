<?php

declare(strict_types=1);

namespace Modules\Wallet\Actions;

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;

/**
 * Adds money to a team's wallet — a recharge, a bonus, or an operator
 * adjustment — and writes the ledger line.
 *
 * Runs inside the team's tenant context so the scoped models resolve to the
 * right team, and locks the wallet row so a concurrent credit or charge reads
 * the balance this one is about to change, not a stale copy.
 */
class CreditWallet
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Team $team, int $amountCents, WalletTransactionType $type, string $description, ?Model $reference = null): WalletTransaction
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Crédito precisa de valor positivo.');
        }

        if (! $type->isCredit()) {
            throw new InvalidArgumentException("O tipo [{$type->value}] não credita a carteira.");
        }

        return $this->tenants->forTenant($team, fn (): WalletTransaction => DB::transaction(function () use ($team, $amountCents, $type, $description, $reference): WalletTransaction {
            $wallet = Wallet::forTeam($team);
            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            $newBalance = $wallet->balance_cents + $amountCents;

            $transaction = $wallet->transactions()->create([
                'type' => $type,
                'amount_cents' => $amountCents,
                'balance_after_cents' => $newBalance,
                'description' => $description,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);

            $wallet->forceFill(['balance_cents' => $newBalance])->save();

            return $transaction;
        }));
    }
}
