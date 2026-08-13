<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Team switching is now exclusively the switcher's job — URL-driven switching
 * was removed (see TeamlessUrlsTest), so `switchTeam` deserves direct coverage.
 */
class TeamSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_teams_updates_the_current_team_and_redirects_to_the_teamless_dashboard(): void
    {
        $user = User::factory()->create();
        $user->personalTeam();
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($user);
        $user->assignTeamRole($otherTeam, TeamRole::Member);

        $this->actingAs($user);

        Livewire::test('team-switcher')
            ->call('switchTeam', $otherTeam->slug)
            ->assertRedirect(route('dashboard'));

        $this->assertEquals($otherTeam->id, $user->fresh()->current_team_id);
    }

    public function test_switching_to_a_team_the_user_does_not_belong_to_is_refused(): void
    {
        $user = User::factory()->create();
        $personalTeam = $user->personalTeam();
        $otherTeam = Team::factory()->create();

        $this->actingAs($user);

        Livewire::test('team-switcher')
            ->call('switchTeam', $otherTeam->slug)
            ->assertForbidden();

        $this->assertEquals($personalTeam->id, $user->fresh()->current_team_id);
    }
}
