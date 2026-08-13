<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Wallet\Actions\CreditWallet;
use Modules\Wallet\Actions\ReserveCredit;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Models\Wallet;

function fund(Team $team, int $cents): void
{
    resolve(CreditWallet::class)->handle($team, $cents, WalletTransactionType::Recharge, 'Fundo de teste');
}

it('reserva dentro do saldo e cria hold ativo', function (): void {
    $team = Team::factory()->create();
    fund($team, 1000);

    $hold = resolve(ReserveCredit::class)->handle($team, 350);

    // available() soma holds ativos numa relação escopada por tenant; fora de
    // forTenant() o TenantScope fecha por padrão (0=1), então a leitura
    // precisa do tenant explícito, como em WalletAvailableTest.
    resolve(TenantManager::class)->set($team);

    expect($hold->amount_cents)->toBe(350)
        ->and($hold->status)->toBe(WalletHoldStatus::Active)
        ->and($hold->expires_at->isFuture())->toBeTrue()
        ->and(Wallet::forTeam($team)->available())->toBe(650)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(1000);
});

it('recusa reservar além do disponível', function (): void {
    $team = Team::factory()->create();
    fund($team, 300);

    resolve(ReserveCredit::class)->handle($team, 350);
})->throws(InsufficientBalanceException::class);

it('duas reservas não gastam o mesmo saldo (anti double-spend)', function (): void {
    $team = Team::factory()->create();
    fund($team, 500);
    $reserve = resolve(ReserveCredit::class);

    $reserve->handle($team, 300); // disponível cai para 200

    // a segunda pede 300, só há 200 disponível
    expect(fn () => $reserve->handle($team, 300))->toThrow(InsufficientBalanceException::class);
});
