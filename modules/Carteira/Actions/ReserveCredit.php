<?php

declare(strict_types=1);

namespace Modules\Carteira\Actions;

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Facades\DB;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Exceptions\InsufficientBalanceException;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;

/**
 * Reserves money for an in-flight operation, or refuses when there is not
 * enough available.
 *
 * The wallet row is locked for the whole check-then-hold, so two operations
 * racing for the last of the balance cannot both pass the check: the second
 * waits for the first's transaction to commit, then sees the reduced available
 * and is refused. Without the lock, both would read the old balance and both
 * would hold — the double-spend this exists to prevent.
 */
class ReserveCredit
{
    /**
     * Quanto tempo um hold sobrevive sem ser confirmado ou solto, antes do
     * expurgo recolhê-lo. Curto porque a execução é síncrona; o hold só dura
     * mais que isso se o processo morreu no meio.
     */
    public const int HOLD_TTL_MINUTES = 15;

    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Team $team, int $amountCents): WalletHold
    {
        return $this->tenants->forTenant($team, fn (): WalletHold => DB::transaction(function () use ($team, $amountCents): WalletHold {
            $wallet = Wallet::forTeam($team);
            // Trava a linha antes de ler o disponível: o lock segura qualquer
            // outra reserva do mesmo time até esta transação commitar.
            Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();

            $available = $wallet->fresh()->available();

            if ($available < $amountCents) {
                throw InsufficientBalanceException::because($available, $amountCents);
            }

            return $wallet->holds()->create([
                'amount_cents' => $amountCents,
                'status' => WalletHoldStatus::Active,
                'expires_at' => now()->addMinutes(self::HOLD_TTL_MINUTES),
            ]);
        }));
    }
}
