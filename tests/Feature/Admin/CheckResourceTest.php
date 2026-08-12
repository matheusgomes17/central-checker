<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CheckerApi\Enums\CheckStatus;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Tests\TestCase;

/**
 * This is the first editable resource in the operator panel — every sibling
 * resource so far is read-only. The money conversion (reais typed, centavos
 * stored) is the sharpest edge, so it gets two tests of its own: one proving
 * the write direction (4.90 -> 490) and one proving the read direction
 * (1234 -> "12.34").
 */
class CheckResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        resolve(ProviderRegistry::class)->register(FakeProvider::returning());

        $this->actingAs($this->superAdmin());
    }

    public function test_the_list_loads_and_shows_checks(): void
    {
        $checks = Check::factory()->count(3)->create();

        $component = Livewire::test(ListChecks::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords($checks);
    }

    public function test_an_operator_can_create_a_check(): void
    {
        Livewire::test(CreateCheck::class)
            ->fillForm([
                'slug' => 'cpf-basico',
                'name' => 'CPF Básico',
                'provider_key' => 'fake',
                'price_cents' => '4.90',
                'status' => CheckStatus::Active->value,
                'timeout_seconds' => 30,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('checks', [
            'slug' => 'cpf-basico',
            'price_cents' => 490,
        ]);
    }

    public function test_editing_shows_price_in_reais(): void
    {
        $check = Check::factory()->create(['price_cents' => 1234]);

        Livewire::test(EditCheck::class, ['record' => $check->getKey()])
            ->assertFormSet(['price_cents' => '12.34']);
    }

    public function test_provider_key_only_accepts_registered_providers(): void
    {
        Livewire::test(CreateCheck::class)
            ->fillForm([
                'slug' => 'cpf-basico',
                'name' => 'CPF Básico',
                'provider_key' => 'inexistente',
                'price_cents' => '4.90',
                'status' => CheckStatus::Active->value,
                'timeout_seconds' => 30,
            ])
            ->call('create')
            ->assertHasFormErrors(['provider_key']);
    }

    public function test_the_maintenance_toggle_flips_status(): void
    {
        $check = Check::factory()->create(['status' => CheckStatus::Active]);

        Livewire::test(ListChecks::class)
            ->callAction(TestAction::make('toggleMaintenance')->table($check));

        $check->refresh();
        $this->assertSame(CheckStatus::Maintenance, $check->status);

        Livewire::test(ListChecks::class)
            ->callAction(TestAction::make('toggleMaintenance')->table($check));

        $check->refresh();
        $this->assertSame(CheckStatus::Active, $check->status);
    }

    public function test_the_maintenance_toggle_leaves_inactive_checks_alone(): void
    {
        $check = Check::factory()->create(['status' => CheckStatus::Inactive]);

        Livewire::test(ListChecks::class)
            ->assertTableActionHidden('toggleMaintenance', $check);
    }

    public function test_the_panel_stays_shut_for_an_ordinary_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/checks')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
