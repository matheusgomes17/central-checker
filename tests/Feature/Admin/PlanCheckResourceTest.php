<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\PlanChecks\Pages\CreatePlanCheck;
use App\Filament\Resources\PlanChecks\Pages\ListPlanChecks;
use App\Models\PlanCheck;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CheckerApi\Models\Check;
use Tests\TestCase;

/**
 * The second editable resource in the operator panel. Its sharpest edge is
 * the (plan, check_id) uniqueness: the database enforces it (23000), but the
 * form must turn that into a friendly error instead of a 500.
 */
class PlanCheckResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());
    }

    public function test_the_list_loads_and_shows_inclusions(): void
    {
        $planChecks = PlanCheck::factory()->count(3)->create();

        $component = Livewire::test(ListPlanChecks::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords($planChecks);
    }

    public function test_an_operator_can_include_a_check_in_a_plan_with_a_quota(): void
    {
        $check = Check::factory()->create();

        Livewire::test(CreatePlanCheck::class)
            ->fillForm([
                'plan' => 'pro',
                'check_id' => $check->id,
                'quota' => 100,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('plan_checks', [
            'plan' => 'pro',
            'check_id' => $check->id,
            'quota' => 100,
        ]);
    }

    public function test_an_empty_quota_means_unlimited(): void
    {
        $check = Check::factory()->create();

        Livewire::test(CreatePlanCheck::class)
            ->fillForm([
                'plan' => 'pro',
                'check_id' => $check->id,
                'quota' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('plan_checks', [
            'plan' => 'pro',
            'check_id' => $check->id,
            'quota' => null,
        ]);

        $planCheck = PlanCheck::query()->where('plan', 'pro')->where('check_id', $check->id)->firstOrFail();

        $this->assertTrue($planCheck->isUnlimited());
    }

    public function test_the_same_plan_and_check_cannot_be_added_twice(): void
    {
        $check = Check::factory()->create();
        PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id]);

        Livewire::test(CreatePlanCheck::class)
            ->fillForm([
                'plan' => 'pro',
                'check_id' => $check->id,
                'quota' => 50,
            ])
            ->call('create')
            ->assertHasFormErrors(['check_id']);

        $this->assertDatabaseCount('plan_checks', 1);
    }

    public function test_the_panel_stays_shut_for_an_ordinary_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/plan-checks')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
