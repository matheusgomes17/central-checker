<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The role now lives in the permission tables, and only there.
 *
 * Two sources of truth for "what is this person's role here" is a silent
 * authorization bug waiting for the day they disagree, so these tests pin down
 * both that the old column is gone and that the new one is what answers.
 */
class TeamRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_membership_pivot_no_longer_carries_a_role(): void
    {
        $this->assertFalse(Schema::hasColumn('team_members', 'role'));
    }

    public function test_creating_a_team_makes_its_creator_the_owner(): void
    {
        $user = User::factory()->create();

        $team = $user->personalTeam();

        $this->assertInstanceOf(Team::class, $team);
        $this->assertSame(TeamRole::Owner, $user->teamRole($team));
        $this->assertTrue($user->ownsTeam($team));
    }

    /**
     * The assignment carries the team, so the same person can hold different
     * roles in different teams — and a role in one must not answer for the
     * other.
     */
    public function test_a_role_is_confined_to_the_team_it_was_granted_in(): void
    {
        $user = User::factory()->create();

        $other = Team::factory()->create();
        $other->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($other, TeamRole::Member);

        $personal = $user->personalTeam();

        $this->assertInstanceOf(Team::class, $personal);
        $this->assertSame(TeamRole::Owner, $user->teamRole($personal));
        $this->assertSame(TeamRole::Member, $user->teamRole($other));
    }

    public function test_a_user_outside_a_team_has_no_role_in_it(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $personal = $user->personalTeam();

        $this->assertInstanceOf(Team::class, $personal);
        $this->assertNull($stranger->teamRole($personal));
    }

    /**
     * A member holds exactly one role per team. Appending instead of replacing
     * would leave the previous role in force alongside the new one, so a
     * demotion would grant nothing less.
     */
    public function test_assigning_a_role_replaces_the_previous_one(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);

        $user->assignTeamRole($team, TeamRole::Admin);
        $user->assignTeamRole($team, TeamRole::Member);

        $this->assertSame(TeamRole::Member, $user->teamRole($team));

        $assignments = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('team_id', $team->id)
            ->count();

        $this->assertSame(1, $assignments);
    }

    public function test_permissions_follow_the_role_of_the_team_being_asked_about(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, TeamRole::Billing);

        $this->assertFalse($user->hasTeamPermission($team, TeamPermission::UpdateTeam));
        $this->assertTrue($user->hasTeamPermission($team, TeamPermission::ManageBilling));

        // No time pessoal a mesma pessoa é dona, e lá pode tudo.
        $personal = $user->personalTeam();

        $this->assertInstanceOf(Team::class, $personal);
        $this->assertTrue($user->hasTeamPermission($personal, TeamPermission::UpdateTeam));
    }

    public function test_owned_teams_lists_only_the_teams_where_the_user_is_owner(): void
    {
        $user = User::factory()->create();

        $other = Team::factory()->create();
        $other->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($other, TeamRole::Admin);

        $owned = $user->ownedTeams()->pluck('teams.id')->all();

        $personal = $user->personalTeam();

        $this->assertInstanceOf(Team::class, $personal);
        $this->assertSame([$personal->id], $owned);
    }
}
