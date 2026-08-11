<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletTransaction;

it('grava uma linha de razão ligada à carteira', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    $wallet = Wallet::forTeam($team);
    $tx = WalletTransaction::factory()->create([
        'team_id' => $team->id,
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Recharge,
        'amount_cents' => 5000,
        'balance_after_cents' => 5000,
    ]);

    expect($wallet->transactions()->count())->toBe(1)
        ->and($tx->type)->toBe(WalletTransactionType::Recharge);
});

it('é escopado por time', function (): void {
    $tenants = resolve(TenantManager::class);
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $tenants->set($teamA);
    WalletTransaction::factory()->create(['team_id' => $teamA->id, 'wallet_id' => Wallet::forTeam($teamA)->id]);

    $tenants->set($teamB);

    expect(WalletTransaction::query()->count())->toBe(0);
});
