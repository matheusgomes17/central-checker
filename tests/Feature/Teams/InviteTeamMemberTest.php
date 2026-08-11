<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Actions\Teams\InviteTeamMember;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The action's own PHPDoc block explains why it exists: three callers — the
 * dialog, the CSV import and the API — must not each grow a slightly
 * different expiry window or a caller that forgets to send the email at all.
 * These tests are the two halves of that promise.
 */
class InviteTeamMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invitation_carries_the_configured_expiry(): void
    {
        config(['teams.invitation_expires_days' => 5]);

        [$team, $owner] = $this->teamWithOwner();

        $invitation = resolve(InviteTeamMember::class)->handle($team, 'convite@example.test', TeamRole::Member, $owner);

        $this->assertNotNull($invitation->expires_at);
        $this->assertTrue($invitation->expires_at->isSameDay(now()->addDays(5)));
    }

    public function test_the_invitation_notification_is_sent_to_the_invited_email(): void
    {
        Notification::fake();

        [$team, $owner] = $this->teamWithOwner();

        resolve(InviteTeamMember::class)->handle($team, 'convite@example.test', TeamRole::Member, $owner);

        Notification::assertSentOnDemand(
            TeamInvitationNotification::class,
            fn (TeamInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'convite@example.test',
        );
    }

    /**
     * @return array{Team, User}
     */
    private function teamWithOwner(): array
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        return [$team, $owner];
    }
}
