<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Facades\Artisan;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;

// Usa a facade Artisan em vez de $this->artisan(): dentro de closures it()/test()
// do Pest, $this é tipado pelo stub do próprio Pest como TestCall, cujo @mixin
// aponta para PHPUnit\Framework\TestCase — sem o artisan() que só existe na
// InteractsWithConsole do TestCase do Laravel. A facade evita a ambiguidade.
it('solta holds ativos vencidos e mantém os recentes', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $wallet = Wallet::forTeam($team);

    $expired = WalletHold::factory()->expired()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id]);
    $fresh = WalletHold::factory()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id]);

    expect(Artisan::call('wallet:expire-holds'))->toBe(0);

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

    expect(Artisan::call('wallet:expire-holds'))->toBe(0);

    expect($settled->fresh()->status)->toBe(WalletHoldStatus::Settled);
});
