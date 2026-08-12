<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\CheckExecutions\CheckExecutionResource;
use App\Filament\Resources\CheckExecutions\Pages\ListCheckExecutions;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CheckerApi\Models\CheckExecution;
use Tests\TestCase;

/**
 * The audit trail of check runs. It must stay entirely read-only, drop
 * TenantScope like every other audit resource so the operator sees every
 * team's runs, and — the point of this resource — never expose the raw
 * `result` column, which holds a third party's unmasked personal data.
 */
class CheckExecutionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());
    }

    public function test_the_list_shows_executions_across_teams(): void
    {
        $executions = [
            CheckExecution::factory()->create(),
            CheckExecution::factory()->create(),
        ];

        $component = Livewire::test(ListCheckExecutions::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords($executions);
    }

    public function test_executions_are_read_only(): void
    {
        $execution = CheckExecution::factory()->create();

        $this->assertFalse(CheckExecutionResource::canCreate());
        $this->assertFalse(CheckExecutionResource::canEdit($execution));
        $this->assertFalse(CheckExecutionResource::canDelete($execution));
        $this->assertArrayNotHasKey('edit', CheckExecutionResource::getPages());
        $this->assertArrayNotHasKey('view', CheckExecutionResource::getPages());
    }

    public function test_the_raw_result_is_never_exposed_in_the_table(): void
    {
        CheckExecution::factory()->create([
            'result' => ['segredo' => 'PII-NAO-VAZAR'],
            'input_masked' => ['cpf' => '123******01'],
        ]);

        $component = Livewire::test(ListCheckExecutions::class);

        $component->assertDontSee('PII-NAO-VAZAR');
        $component->assertSee('123******01');
    }

    public function test_the_panel_stays_shut_for_an_ordinary_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/check-executions')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
