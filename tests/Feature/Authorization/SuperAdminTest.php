<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\SystemRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The grant carries no team, so it has to answer the same from anywhere.
     * A super admin who stops being one on entering a team would be a
     * privilege that quietly disappears.
     */
    public function test_the_grant_is_platform_wide_and_survives_any_tenant(): void
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);

        $this->assertTrue($user->isSuperAdmin());

        $team = Team::factory()->create();
        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, TeamRole::Member);
        $user->switchTeam($team);

        $this->assertTrue($user->isSuperAdmin());
        $this->assertSame(TeamRole::Member, $user->teamRole($team));

        $assignment = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.name', SystemRole::SuperAdmin->value)
            ->value('model_has_roles.team_id');

        $this->assertNull($assignment);
    }

    public function test_a_super_admin_passes_every_check(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->setSystemRole(SystemRole::SuperAdmin);

        $someoneElsesTeam = Team::factory()->create();

        $this->assertTrue($superAdmin->can('update', $someoneElsesTeam));
        $this->assertTrue($superAdmin->can('delete', $someoneElsesTeam));
        $this->assertTrue($superAdmin->can('removeMember', $someoneElsesTeam));
    }

    /**
     * The gate hook has to answer null, not false, for everyone else. False
     * would be a verdict, and the policies that should decide would never run.
     */
    public function test_an_ordinary_user_is_still_judged_by_the_policies(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $team->memberships()->create(['user_id' => $owner->id]);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $this->assertTrue($owner->can('update', $team));
        $this->assertFalse($owner->can('update', Team::factory()->create()));
    }

    public function test_the_admin_panel_is_closed_to_ordinary_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_the_admin_panel_opens_for_a_super_admin(): void
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    /**
     * The environment fallback used to be the only thing shutting the panel.
     * Now that an access rule exists, production must not be the difference.
     */
    public function test_the_panel_stays_closed_to_ordinary_users_even_locally(): void
    {
        config(['app.env' => 'local']);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_the_command_grants_and_revokes(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('user:super-admin', ['email' => 'ada@example.com'])->assertSuccessful();
        $this->assertTrue($user->fresh()->isSuperAdmin());

        $this->artisan('user:super-admin', ['email' => 'ada@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    public function test_the_command_refuses_an_unknown_email(): void
    {
        $this->artisan('user:super-admin', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
