<?php

declare(strict_types=1);

use App\Models\Team;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;

it('credita a carteira e grava a linha de razão', function (): void {
    $team = Team::factory()->create();

    $tx = resolve(CreditWallet::class)->handle($team, 5000, WalletTransactionType::Recharge, 'Recarga PIX');

    expect($tx->amount_cents)->toBe(5000)
        ->and($tx->balance_after_cents)->toBe(5000)
        ->and($tx->type)->toBe(WalletTransactionType::Recharge)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(5000);
});

it('soma créditos sucessivos no balance_after', function (): void {
    $team = Team::factory()->create();
    $credit = resolve(CreditWallet::class);

    $credit->handle($team, 5000, WalletTransactionType::Recharge, 'Recarga');
    $second = $credit->handle($team, 2000, WalletTransactionType::Bonus, 'Bônus');

    expect($second->balance_after_cents)->toBe(7000)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(7000);
});

it('recusa valor não positivo', function (): void {
    $team = Team::factory()->create();

    resolve(CreditWallet::class)->handle($team, 0, WalletTransactionType::Recharge, 'Zero');
})->throws(InvalidArgumentException::class);

it('recusa tipo que não credita', function (): void {
    $team = Team::factory()->create();

    resolve(CreditWallet::class)->handle($team, 100, WalletTransactionType::Charge, 'Débito indevido');
})->throws(InvalidArgumentException::class);
