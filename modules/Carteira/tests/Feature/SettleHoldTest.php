<?php

declare(strict_types=1);

use App\Models\Team;
use Modules\Carteira\Actions\ConfirmHold;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Actions\ReleaseHold;
use Modules\Carteira\Actions\ReserveCredit;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Exceptions\HoldNotActiveException;
use Modules\Carteira\Models\Wallet;

function funded(int $cents): Team
{
    $team = Team::factory()->create();
    resolve(CreditWallet::class)->handle($team, $cents, WalletTransactionType::Recharge, 'Fundo');

    return $team;
}

it('confirmar um hold debita a carteira e grava a cobrança', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);

    $tx = resolve(ConfirmHold::class)->handle($hold, 'Consulta X');

    expect($tx->type)->toBe(WalletTransactionType::Charge)
        ->and($tx->amount_cents)->toBe(-350)
        ->and($tx->balance_after_cents)->toBe(650)
        ->and($hold->fresh()->status)->toBe(WalletHoldStatus::Settled)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(650)
        ->and(Wallet::forTeam($team)->available())->toBe(650);
});

it('soltar um hold não debita e devolve o disponível', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);

    resolve(ReleaseHold::class)->handle($hold);

    expect($hold->fresh()->status)->toBe(WalletHoldStatus::Released)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(1000)
        ->and(Wallet::forTeam($team)->available())->toBe(1000);
});

it('soltar um hold já solto é no-op', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);
    $release = resolve(ReleaseHold::class);

    $release->handle($hold);
    $release->handle($hold->fresh());

    expect($hold->fresh()->status)->toBe(WalletHoldStatus::Released);
});

it('não confirma um hold já solto', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);
    resolve(ReleaseHold::class)->handle($hold);

    resolve(ConfirmHold::class)->handle($hold->fresh(), 'Tarde demais');
})->throws(HoldNotActiveException::class);

it('não solta um hold já confirmado', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);
    resolve(ConfirmHold::class)->handle($hold, 'Consulta X');

    resolve(ReleaseHold::class)->handle($hold->fresh());
})->throws(HoldNotActiveException::class);
