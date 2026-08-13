<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\Wallets\Pages\ListWallets;
use App\Filament\Resources\Wallets\WalletResource;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;
use Tests\TestCase;

/**
 * Like UserCheckOverrideResource, the sharpest edge here is the TenantScope
 * drop on getEloquentQuery() — without it the list is always empty. The
 * second edge is the credit row action: it must go through CreditWallet so
 * the credit lands as an `adjustment` line in the ledger, never a bare
 * balance write.
 */
class WalletResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());
    }

    public function test_the_list_shows_wallets_across_teams(): void
    {
        $wallets = [
            Wallet::factory()->create(),
            Wallet::factory()->create(),
        ];

        $component = Livewire::test(ListWallets::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords($wallets);
    }

    public function test_wallets_are_read_only(): void
    {
        $wallet = Wallet::factory()->create();

        $this->assertFalse(WalletResource::canCreate());
        $this->assertFalse(WalletResource::canEdit($wallet));
        $this->assertFalse(WalletResource::canDelete($wallet));
    }

    public function test_an_operator_can_credit_a_wallet_and_it_becomes_an_adjustment(): void
    {
        $team = Team::factory()->create();
        $wallet = Wallet::factory()->create(['team_id' => $team->id, 'balance_cents' => 0]);

        Livewire::test(ListWallets::class)
            ->callAction(TestAction::make('credit')->table($wallet), data: [
                'amount' => '10.00',
                'reason' => 'cortesia',
            ]);

        $wallet->refresh();
        $this->assertSame(1000, $wallet->balance_cents);

        $this->assertDatabaseHas('wallet_transactions', [
            'team_id' => $team->id,
            'type' => 'adjustment',
            'amount_cents' => 1000,
            'description' => 'cortesia',
        ]);
    }

    /**
     * The available-balance column must subtract active holds from the
     * balance — not just show balance_cents back — even in this tenant-less
     * panel context (getEloquentQuery() drops TenantScope for the admin).
     */
    public function test_the_available_column_subtracts_an_active_hold_from_the_balance(): void
    {
        $team = Team::factory()->create();
        $wallet = Wallet::factory()->create(['team_id' => $team->id, 'balance_cents' => 10000]);
        WalletHold::factory()->create([
            'team_id' => $team->id,
            'wallet_id' => $wallet->id,
            'amount_cents' => 3000,
            'status' => WalletHoldStatus::Active,
        ]);

        $this->assertSame(7000, $wallet->available());

        // Sem o símbolo: o ICU insere espaço não separável entre a moeda e o
        // número, que sai escapado no HTML e nunca casaria literalmente.
        Livewire::test(ListWallets::class)
            ->assertOk()
            ->assertSee('70,00');
    }

    public function test_the_panel_stays_shut_for_an_ordinary_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/wallets')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
