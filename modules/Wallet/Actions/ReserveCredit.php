<?php

declare(strict_types=1);

namespace Modules\Wallet\Actions;

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;

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
     * How long a hold survives without being confirmed or released, before
     * the sweep collects it. Short because execution is synchronous; the
     * hold only lasts longer than this if the process died midway.
     */
    public const int HOLD_TTL_MINUTES = 15;

    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Team $team, int $amountCents): WalletHold
    {
        return $this->tenants->forTenant($team, fn (): WalletHold => DB::transaction(function () use ($team, $amountCents): WalletHold {
            $wallet = Wallet::forTeam($team);
            // Trava a linha antes de ler o disponível: o lock segura qualquer
            // outra reserva do mesmo time até esta transação commitar.
            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            $available = $locked->available();

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
