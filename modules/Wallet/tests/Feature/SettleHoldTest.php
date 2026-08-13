<?php

declare(strict_types=1);

use App\Models\Team;
use Modules\Wallet\Actions\ConfirmHold;
use Modules\Wallet\Actions\CreditWallet;
use Modules\Wallet\Actions\ReleaseHold;
use Modules\Wallet\Actions\ReserveCredit;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\HoldNotActiveException;
use Modules\Wallet\Models\Wallet;

function funded(int $cents): Team
{
    $team = Team::factory()->create();
    resolve(CreditWallet::class)->handle($team, $cents, WalletTransactionType::Recharge, 'Fundo');

    return $team;
}

it('confirmar um hold debita a carteira e grava a cobrança', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);

    $tx = resolve(ConfirmHold::class)->handle($hold, 'Check X');

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
    $release->handle($hold->refresh());

    expect($hold->fresh()->status)->toBe(WalletHoldStatus::Released);
});

it('não confirma um hold já solto', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);
    resolve(ReleaseHold::class)->handle($hold);

    resolve(ConfirmHold::class)->handle($hold->refresh(), 'Tarde demais');
})->throws(HoldNotActiveException::class);

it('não solta um hold já confirmado', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);
    resolve(ConfirmHold::class)->handle($hold, 'Check X');

    resolve(ReleaseHold::class)->handle($hold->refresh());
})->throws(HoldNotActiveException::class);
