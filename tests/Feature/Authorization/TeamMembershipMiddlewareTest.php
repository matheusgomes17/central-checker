<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The middleware is the team boundary on the web routes, and until now what
 * exercised it were the Livewire component tests — which run after it and never
 * go through it. Mutation testing found the hole: swapping the `||` in the
 * abort_if for `&&` let a non-member in and no test complained.
 *
 * No application route passes the minimum role argument today. The routes
 * registered here exist to exercise it: code only reached once someone decides
 * to use it is code that breaks without warning.
 */
class TeamMembershipMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_member_cannot_reach_another_teams_dashboard(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertForbidden();
    }

    public function test_an_unknown_team_slug_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => 'time-que-nao-existe']))
            ->assertForbidden();
    }

    public function test_reaching_a_team_dashboard_switches_the_current_team(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Member);

        $this->assertFalse($user->isCurrentTeam($team));

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertOk();

        $this->assertTrue($user->fresh()->isCurrentTeam($team));
    }

    public function test_the_team_settings_page_does_not_switch_the_current_team(): void
    {
        $user = User::factory()->create();
        $personalTeam = $user->personalTeam();
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Owner);

        $this->actingAs($user)
            ->get(route('teams.edit', $team))
            ->assertOk();

        $this->assertTrue($user->fresh()->isCurrentTeam($personalTeam));
    }

    public function test_a_minimum_role_refuses_a_member_below_it(): void
    {
        $this->defineRouteRequiring('admin');

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Member);

        $this->actingAs($user)
            ->get("__test/teams/{$team->slug}")
            ->assertForbidden();
    }

    public function test_a_minimum_role_admits_a_member_above_it(): void
    {
        $this->defineRouteRequiring('admin');

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Owner);

        $this->actingAs($user)
            ->get("__test/teams/{$team->slug}")
            ->assertNoContent();
    }

    public function test_a_member_without_any_role_is_refused(): void
    {
        $this->defineRouteRequiring('admin');

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user);

        $this->actingAs($user)
            ->get("__test/teams/{$team->slug}")
            ->assertForbidden();
    }

    public function test_a_role_that_does_not_exist_refuses_even_the_owner(): void
    {
        $this->defineRouteRequiring('imperador');

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Owner);

        $this->actingAs($user)
            ->get("__test/teams/{$team->slug}")
            ->assertForbidden();
    }

    /**
     * Register a route guarded by the middleware with the given minimum role.
     */
    protected function defineRouteRequiring(string $minimumRole): void
    {
        Route::middleware(['web', 'auth', EnsureTeamMembership::class.':'.$minimumRole])
            ->get('__test/teams/{team}', fn () => response()->noContent());
    }
}
