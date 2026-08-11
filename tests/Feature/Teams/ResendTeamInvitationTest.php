<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ResendTeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->team = Team::factory()->create();
        $this->owner = User::factory()->create();

        $this->team->memberships()->create(['user_id' => $this->owner->id]);
        $this->owner->assignTeamRole($this->team, TeamRole::Owner);
    }

    public function test_it_sends_the_invitation_again(): void
    {
        $invitation = $this->invitation();

        $this->actingAs($this->owner);

        Livewire::test('pages::teams.edit', ['team' => $this->team])
            ->call('resendInvitation', $invitation->code);

        Notification::assertSentOnDemand(TeamInvitationNotification::class);
    }

    /**
     * Sending a lapsed invitation again is pointless unless the deadline
     * moves with it — which is the usual reason to send it again at all.
     */
    public function test_it_renews_an_expired_invitation(): void
    {
        $invitation = $this->invitation(['expires_at' => now()->subDay()]);

        $this->actingAs($this->owner);

        Livewire::test('pages::teams.edit', ['team' => $this->team])
            ->call('resendInvitation', $invitation->code);

        $this->assertFalse($invitation->fresh()->isExpired());
    }

    /**
     * Without a cooldown the button is a mail cannon pointed at a fixed
     * address.
     */
    public function test_it_refuses_a_second_send_inside_the_cooldown(): void
    {
        config(['teams.invitation_resend_cooldown_minutes' => 5]);

        $invitation = $this->invitation();

        $this->actingAs($this->owner);

        $component = Livewire::test('pages::teams.edit', ['team' => $this->team]);

        $component->call('resendInvitation', $invitation->code);
        $component->call('resendInvitation', $invitation->code);

        Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 1);
    }

    public function test_it_refuses_an_invitation_that_was_already_accepted(): void
    {
        $invitation = $this->invitation(['accepted_at' => now()]);

        $this->actingAs($this->owner);

        Livewire::test('pages::teams.edit', ['team' => $this->team])
            ->call('resendInvitation', $invitation->code);

        Notification::assertNothingSent();
    }

    /**
     * The code is a bearer credential. Scoping the lookup to the team on the
     * page is what stops an admin of one team from acting on an invitation that
     * belongs to a different team.
     */
    public function test_it_refuses_a_code_belonging_to_another_team(): void
    {
        $otherTeam = Team::factory()->create();
        $foreign = TeamInvitation::create([
            'team_id' => $otherTeam->id,
            'email' => 'outsider@example.com',
            'role' => TeamRole::Member,
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->owner);

        // O firstOrFail escapa como ModelNotFoundException, que numa
        // requisição de verdade o handler converte em 404.
        $this->assertThrows(
            fn () => Livewire::test('pages::teams.edit', ['team' => $this->team])
                ->call('resendInvitation', $foreign->code),
            ModelNotFoundException::class,
        );

        Notification::assertNothingSent();
    }

    /**
     * A lapsed invitation has already given its seat back — the counter stops
     * counting it the moment it expires. Sending it again pushes the deadline
     * into the future, which takes the seat back, so it is an addition and has
     * to ask the ceiling like any other. Without asking, a free team at three of
     * three reaches four by pressing a button that only claims to resend.
     */
    public function test_it_refuses_to_renew_a_lapsed_invitation_with_no_seat_left(): void
    {
        $member = User::factory()->create();
        $this->team->memberships()->create(['user_id' => $member->id]);
        $member->assignTeamRole($this->team, TeamRole::Member);

        TeamInvitation::create([
            'team_id' => $this->team->id,
            'email' => 'waiting@example.com',
            'role' => TeamRole::Member,
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(3),
        ]);

        $lapsed = $this->invitation(['expires_at' => now()->subDay()]);

        $this->actingAs($this->owner);

        Livewire::test('pages::teams.edit', ['team' => $this->team])
            ->call('resendInvitation', $lapsed->code);

        Notification::assertNothingSent();
        $this->assertTrue($lapsed->fresh()?->isExpired());
    }

    public function test_a_plain_member_cannot_resend(): void
    {
        $invitation = $this->invitation();

        $member = User::factory()->create();
        $this->team->memberships()->create(['user_id' => $member->id]);
        $member->assignTeamRole($this->team, TeamRole::Member);

        $this->actingAs($member);

        Livewire::test('pages::teams.edit', ['team' => $this->team])
            ->call('resendInvitation', $invitation->code)
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function invitation(array $attributes = []): TeamInvitation
    {
        $invitation = TeamInvitation::create([
            'team_id' => $this->team->id,
            'email' => 'invitee@example.com',
            'role' => TeamRole::Member,
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(3),
        ]);

        // Direto pelo query builder de propósito. saveQuietly() silencia
        // eventos, não timestamps: o Eloquent recarimba updated_at sempre que
        // o valor informado não fica sujo, e é justamente updated_at que o
        // cooldown lê.
        DB::table('team_invitations')
            ->where('id', $invitation->id)
            ->update(['updated_at' => now()->subHour(), ...$attributes]);

        $invitation->refresh();

        return $invitation;
    }
}
