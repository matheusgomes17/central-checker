<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\UserCheckOverrides\Pages\CreateUserCheckOverride;
use App\Filament\Resources\UserCheckOverrides\Pages\EditUserCheckOverride;
use App\Filament\Resources\UserCheckOverrides\Pages\ListUserCheckOverrides;
use App\Models\Team;
use App\Models\User;
use App\Models\UserCheckOverride;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CheckerApi\Models\Check;
use Tests\TestCase;

/**
 * The panel has no ambient tenant, so this resource's sharpest edge is the
 * TenantScope drop on getEloquentQuery() — without it the list is always
 * empty. The second edge is team_id: it is deliberately outside the model's
 * Fillable list (the trait normally fills it from the tenant), so the create
 * page has to force it in by hand.
 */
class UserCheckOverrideResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());
    }

    public function test_the_list_shows_overrides_across_teams(): void
    {
        $overrides = [
            UserCheckOverride::factory()->create(),
            UserCheckOverride::factory()->create(),
        ];

        $component = Livewire::test(ListUserCheckOverrides::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords($overrides);
    }

    public function test_an_operator_can_grant_a_check_for_a_user(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $check = Check::factory()->create();

        Livewire::test(CreateUserCheckOverride::class)
            ->fillForm([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'check_id' => $check->id,
                'granted' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('user_check_overrides', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'check_id' => $check->id,
            'granted' => true,
        ]);
    }

    public function test_a_custom_price_is_stored_in_cents(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $check = Check::factory()->create();

        Livewire::test(CreateUserCheckOverride::class)
            ->fillForm([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'check_id' => $check->id,
                'price_cents' => '2.50',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('user_check_overrides', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'check_id' => $check->id,
            'price_cents' => 250,
        ]);

        $override = UserCheckOverride::acrossTenants()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('check_id', $check->id)
            ->firstOrFail();

        Livewire::test(EditUserCheckOverride::class, ['record' => $override->getKey()])
            ->assertFormSet(['price_cents' => '2.50']);
    }

    public function test_the_same_trio_cannot_be_added_twice(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $check = Check::factory()->create();

        UserCheckOverride::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'check_id' => $check->id,
        ]);

        Livewire::test(CreateUserCheckOverride::class)
            ->fillForm([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'check_id' => $check->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['check_id']);

        $this->assertDatabaseCount('user_check_overrides', 1);

        $anotherUser = User::factory()->create();

        Livewire::test(CreateUserCheckOverride::class)
            ->fillForm([
                'team_id' => $team->id,
                'user_id' => $anotherUser->id,
                'check_id' => $check->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('user_check_overrides', 2);

        $anotherCheck = Check::factory()->create();

        Livewire::test(CreateUserCheckOverride::class)
            ->fillForm([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'check_id' => $anotherCheck->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('user_check_overrides', 3);
    }

    public function test_the_panel_stays_shut_for_an_ordinary_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/user-check-overrides')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
