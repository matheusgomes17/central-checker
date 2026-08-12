<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Carteira\Models\WalletTransaction;
use Tests\TestCase;

/**
 * The razão is append-only — a correction is another line, never an edit —
 * so this resource must stay read-only, and, like WalletResource, must drop
 * TenantScope on getEloquentQuery() to see every team's lines.
 */
class WalletTransactionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());
    }

    public function test_the_ledger_shows_lines_across_teams(): void
    {
        $transactions = [
            WalletTransaction::factory()->create(),
            WalletTransaction::factory()->create(),
        ];

        $component = Livewire::test(ListWalletTransactions::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords($transactions);
    }

    public function test_the_ledger_is_append_only(): void
    {
        $transaction = WalletTransaction::factory()->create();

        $this->assertFalse(WalletTransactionResource::canCreate());
        $this->assertFalse(WalletTransactionResource::canEdit($transaction));
        $this->assertFalse(WalletTransactionResource::canDelete($transaction));
    }

    public function test_the_panel_stays_shut_for_an_ordinary_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/wallet-transactions')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
