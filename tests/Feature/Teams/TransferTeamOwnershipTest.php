<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Actions\Teams\TransferTeamOwnership;
use App\Enums\TeamRole;
use App\Exceptions\OwnershipTransferException;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TransferTeamOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private TransferTeamOwnership $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transfer = resolve(TransferTeamOwnership::class);
    }

    /**
     * The invariant: exactly one owner before, exactly one after. Two owners
     * would quietly turn every "is the owner" check into "is an owner".
     */
    public function test_it_moves_ownership_and_leaves_exactly_one_owner(): void
    {
        [$team, $owner, $member] = $this->team();

        $this->transfer->handle($team, $owner, $member);

        $this->assertTrue($member->fresh()->ownsTeam($team));
        $this->assertFalse($owner->fresh()->ownsTeam($team));

        $owners = $team->members()
            ->get()
            ->filter(fn (User $member): bool => $member->teamRole($team) === TeamRole::Owner)
            ->count();

        $this->assertSame(1, $owners);
    }

    /**
     * The previous owner keeps administering the team. Dropping them entirely
     * would make transfer a disguised way of leaving.
     */
    public function test_the_previous_owner_becomes_an_admin(): void
    {
        [$team, $owner, $member] = $this->team();

        $this->transfer->handle($team, $owner, $member);

        $this->assertSame(TeamRole::Admin, $owner->fresh()->teamRole($team));
    }

    public function test_only_the_owner_can_transfer(): void
    {
        [$team, $owner, $member] = $this->team();

        $this->expectException(OwnershipTransferException::class);

        $this->transfer->handle($team, $member, $owner);
    }

    public function test_it_refuses_a_user_outside_the_team(): void
    {
        [$team, $owner] = $this->team();

        $stranger = User::factory()->create();

        $this->expectException(OwnershipTransferException::class);

        $this->transfer->handle($team, $owner, $stranger);
    }

    public function test_it_refuses_transferring_to_yourself(): void
    {
        [$team, $owner] = $this->team();

        $this->expectException(OwnershipTransferException::class);

        $this->transfer->handle($team, $owner, $owner);
    }

    /**
     * A personal team is the person's account under another name. Handing it
     * over would leave the original owner inside a personal team
     * belonging to another person.
     */
    public function test_it_refuses_a_personal_team(): void
    {
        [$team, $owner, $member] = $this->team(personal: true);

        $this->expectException(OwnershipTransferException::class);

        $this->transfer->handle($team, $owner, $member);
    }

    public function test_a_failed_transfer_leaves_the_roles_untouched(): void
    {
        [$team, $owner, $member] = $this->team(personal: true);

        try {
            $this->transfer->handle($team, $owner, $member);
        } catch (OwnershipTransferException) {
            // esperado
        }

        $this->assertSame(TeamRole::Owner, $owner->fresh()->teamRole($team));
        $this->assertSame(TeamRole::Member, $member->fresh()->teamRole($team));
    }

    public function test_the_policy_keeps_admins_out(): void
    {
        [$team, $owner, $member] = $this->team();

        $admin = User::factory()->create();
        $team->memberships()->create(['user_id' => $admin->id]);
        $admin->assignTeamRole($team, TeamRole::Admin);

        $this->assertTrue($owner->can('transferOwnership', $team));
        $this->assertFalse($admin->can('transferOwnership', $team));
        $this->assertFalse($member->can('transferOwnership', $team));
    }

    public function test_the_modal_requires_the_current_password(): void
    {
        [$team, $owner, $member] = $this->team();

        $this->actingAs($owner);

        Livewire::test('pages::teams.transfer-ownership-modal', ['team' => $team])
            ->set('newOwnerId', $member->id)
            ->set('password', 'not-the-password')
            ->call('transfer')
            ->assertHasErrors('password');

        $this->assertTrue($owner->fresh()->ownsTeam($team));
    }

    public function test_the_modal_transfers_with_the_correct_password(): void
    {
        [$team, $owner, $member] = $this->team();

        $this->actingAs($owner);

        Livewire::test('pages::teams.transfer-ownership-modal', ['team' => $team])
            ->set('newOwnerId', $member->id)
            ->set('password', 'password')
            ->call('transfer')
            ->assertHasNoErrors();

        $this->assertTrue($member->fresh()->ownsTeam($team));
    }

    /**
     * The candidate list is also an access boundary: offering a stranger would
     * be offering to hand the team outside it.
     */
    public function test_the_modal_only_offers_members_other_than_the_owner(): void
    {
        [$team, $owner, $member] = $this->team();

        $stranger = User::factory()->create(['name' => 'Fora Do Time']);

        $this->actingAs($owner);

        Livewire::test('pages::teams.transfer-ownership-modal', ['team' => $team])
            ->assertSee($member->name)
            ->assertDontSee($stranger->name)
            ->assertDontSee($owner->name);
    }

    /**
     * @return array{Team, User, User}
     */
    private function team(bool $personal = false): array
    {
        $team = Team::factory()->create(['is_personal' => $personal]);

        $owner = User::factory()->create();
        $member = User::factory()->create();

        $team->memberships()->create(['user_id' => $owner->id]);

        $owner->assignTeamRole($team, TeamRole::Owner);
        $team->memberships()->create(['user_id' => $member->id]);
        $member->assignTeamRole($team, TeamRole::Member);

        return [$team, $owner, $member];
    }
}
