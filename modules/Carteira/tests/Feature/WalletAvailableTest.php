<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;

it('desconta holds ativos do saldo disponível', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    $wallet = Wallet::forTeam($team);
    $wallet->forceFill(['balance_cents' => 1000])->save();

    WalletHold::factory()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id, 'amount_cents' => 300]);

    expect($wallet->fresh()->available())->toBe(700)
        ->and($wallet->fresh()->balance_cents)->toBe(1000);
});

it('holds soltos ou liquidados não reduzem o disponível', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    $wallet = Wallet::forTeam($team);
    $wallet->forceFill(['balance_cents' => 1000])->save();

    WalletHold::factory()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id, 'amount_cents' => 300, 'status' => Modules\Carteira\Enums\WalletHoldStatus::Released]);

    expect($wallet->fresh()->available())->toBe(1000);
});
