<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Actions\Teams\TransferTeamOwnership;
use App\Enums\AuditEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The trail records changes that move power between people.
 *
 * Each of these is a change someone might later need to explain: who made this
 * person an admin, who took the team over, who removed whom. An entry that
 * only says a row changed would not answer any of those, so the tests assert
 * the properties too.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_role_change_is_recorded_with_both_ends(): void
    {
        [$team, $owner, $member] = $this->team();

        $this->actingAs($owner);

        Livewire::test('pages::teams.edit', ['team' => $team])
            ->call('updateMember', $member->id, TeamRole::Admin->value);

        $activity = $this->latestOf(AuditEvent::TeamRoleChanged);

        $this->assertSame($team->id, $activity->subject_id);
        $this->assertSame($owner->id, $activity->causer_id);
        $properties = $this->propertiesOf($activity);

        $this->assertSame($member->id, $properties['user_id']);
        $this->assertSame(TeamRole::Member->value, $properties['from']);
        $this->assertSame(TeamRole::Admin->value, $properties['to']);
    }

    public function test_an_ownership_transfer_is_recorded(): void
    {
        [$team, $owner, $member] = $this->team();

        resolve(TransferTeamOwnership::class)->handle($team, $owner, $member);

        $activity = $this->latestOf(AuditEvent::TeamOwnershipTransferred);

        $this->assertSame($team->id, $activity->subject_id);
        $properties = $this->propertiesOf($activity);

        $this->assertSame($owner->id, $properties['from_user_id']);
        $this->assertSame($member->id, $properties['to_user_id']);
    }

    public function test_removing_a_member_is_recorded_and_drops_their_role(): void
    {
        [$team, $owner, $member] = $this->team();

        $this->actingAs($owner);

        Livewire::test('pages::teams.remove-member-modal', [
            'team' => $team,
            'memberId' => $member->id,
        ])->call('removeMember');

        $activity = $this->latestOf(AuditEvent::TeamMemberRemoved);

        $this->assertSame($team->id, $activity->subject_id);
        $this->assertSame($member->id, $this->propertiesOf($activity)['user_id']);

        // O papel tem que sair junto: membership removida com papel remanescente
        // deixaria a pessoa fora do time e ainda autorizada nele.
        $this->assertNull($member->fresh()->teamRole($team));
    }

    /**
     * Audit entries go to a log of their own so they never mix with whatever
     * else the application decides to log later.
     */
    public function test_entries_are_written_to_the_audit_log(): void
    {
        [$team, $owner, $member] = $this->team();

        resolve(TransferTeamOwnership::class)->handle($team, $owner, $member);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'audit']);
    }

    /**
     * @return Collection<string, mixed>
     */
    private function propertiesOf(Activity $activity): Collection
    {
        $properties = $activity->properties;

        $this->assertInstanceOf(Collection::class, $properties);

        return $properties;
    }

    private function latestOf(AuditEvent $event): Activity
    {
        $activity = Activity::query()
            ->where('event', $event->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, "Nenhum registro para [{$event->value}].");

        return $activity;
    }

    /**
     * @return array{Team, User, User}
     */
    private function team(): array
    {
        $team = Team::factory()->create(['is_personal' => false]);

        $owner = User::factory()->create();
        $member = User::factory()->create();

        $team->memberships()->create(['user_id' => $owner->id]);
        $team->memberships()->create(['user_id' => $member->id]);

        $owner->assignTeamRole($team, TeamRole::Owner);
        $member->assignTeamRole($team, TeamRole::Member);

        return [$team, $owner, $member];
    }
}
