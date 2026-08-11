<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Teams\InviteTeamMember;
use App\Enums\TeamRole;
use App\Exceptions\PlanLimitException;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A team can end up over its ceiling: the plan changes, the limit drops, and
 * whoever was already there stays. Nobody is removed for it — deciding who
 * leaves the team belongs to the customer — but the state has to be visible,
 * and it must not read like the suspension for an unpaid bill.
 */
class OverTheLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Two halves of one rule, and only together do they say anything.
     *
     * Counting the five memberships on its own asserted that a test fixture is
     * what the test fixture made it: no code removes anyone, so no change to
     * the product could ever break it. What can break is the other half — the
     * addition being refused — and the count matters as the thing that must
     * not move while it is.
     */
    public function test_the_team_over_the_ceiling_is_refused_additions_and_loses_nobody(): void
    {
        ['team' => $team, 'owner' => $owner] = $this->teamOverItsCeiling();

        $this->assertThrows(
            fn () => resolve(InviteTeamMember::class)->handle($team, 'novo@example.test', TeamRole::Member, $owner),
            PlanLimitException::class,
        );

        $this->assertSame(5, $team->memberships()->count());
        $this->assertSame(0, TeamInvitation::query()->where('team_id', $team->id)->count());
    }

    public function test_the_billing_screen_shows_the_excess(): void
    {
        ['team' => $team, 'owner' => $owner] = $this->teamOverItsCeiling();

        $owner->switchTeam($team);

        $this->actingAs($owner)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertSee(__('Above your plan'));
    }

    public function test_usage_reports_the_excess_without_going_negative(): void
    {
        $usage = TeamBilling::for($this->teamOverItsCeiling()['team'])->usage('members');

        $this->assertSame(3, $usage['limit']);
        $this->assertSame(5, $usage['used']);
        $this->assertSame(0, $usage['remaining']);
    }

    /**
     * The owner is created outright, rather than picked back out of the
     * `members()` relation by role. That relation carries no ordering
     * guarantee, and `first()` on an unordered query returns whichever row
     * the database feels like — reliably the right one on a fresh table,
     * unreliably so once the suite has churned through enough other tests.
     *
     * @return array{team: Team, owner: User}
     */
    private function teamOverItsCeiling(): array
    {
        $team = Team::factory()->create();

        $owner = User::factory()->create();
        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        foreach (range(1, 4) as $index) {
            $member = User::factory()->create();
            $team->members()->attach($member);
            $member->assignTeamRole($team, TeamRole::Member);
        }

        return ['team' => $team->refresh(), 'owner' => $owner];
    }
}
