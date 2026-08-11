<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;

it('solta holds ativos vencidos e mantém os recentes', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $wallet = Wallet::forTeam($team);

    $expired = WalletHold::factory()->expired()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id]);
    $fresh = WalletHold::factory()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id]);

    $this->artisan('wallet:expire-holds')->assertExitCode(0);

    expect($expired->fresh()->status)->toBe(WalletHoldStatus::Released)
        ->and($expired->fresh()->released_at)->not->toBeNull()
        ->and($fresh->fresh()->status)->toBe(WalletHoldStatus::Active);
});

it('não toca em hold já liquidado mesmo vencido', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $wallet = Wallet::forTeam($team);

    $settled = WalletHold::factory()->expired()->create([
        'team_id' => $team->id, 'wallet_id' => $wallet->id, 'status' => WalletHoldStatus::Settled,
    ]);

    $this->artisan('wallet:expire-holds')->assertExitCode(0);

    expect($settled->fresh()->status)->toBe(WalletHoldStatus::Settled);
});
