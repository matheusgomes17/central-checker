<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\MemberJoined;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Preferences are stored as opt-outs, so anything added later starts on.
     * The opposite shape would leave every existing account silently missing
     * new notifications.
     */
    public function test_everything_optional_is_on_by_default(): void
    {
        $user = User::factory()->create();

        foreach (NotificationTopic::cases() as $topic) {
            foreach (NotificationChannel::cases() as $channel) {
                $this->assertTrue($user->wantsNotification($topic, $channel));
            }
        }
    }

    public function test_a_user_can_switch_off_a_channel_for_a_topic(): void
    {
        $user = User::factory()->create();

        $user->setNotificationPreference(NotificationTopic::Team, NotificationChannel::Mail, false);

        $user->refresh();

        $this->assertFalse($user->wantsNotification(NotificationTopic::Team, NotificationChannel::Mail));
        // Só aquele par foi desligado.
        $this->assertTrue($user->wantsNotification(NotificationTopic::Team, NotificationChannel::Broadcast));
        $this->assertTrue($user->wantsNotification(NotificationTopic::Billing, NotificationChannel::Mail));
    }

    /**
     * An inbox missing what the account received is worse than a noisy one.
     */
    public function test_the_in_app_record_cannot_be_switched_off(): void
    {
        $user = User::factory()->create();

        $user->setNotificationPreference(NotificationTopic::Team, NotificationChannel::Database, false);

        $this->assertTrue($user->fresh()->wantsNotification(NotificationTopic::Team, NotificationChannel::Database));
    }

    /**
     * Turning off the warning that your password changed turns off the only
     * thing that would tell you it was not you.
     */
    public function test_security_notifications_cannot_be_switched_off(): void
    {
        $user = User::factory()->create();

        $user->setNotificationPreference(NotificationTopic::Security, NotificationChannel::Mail, false);

        $this->assertTrue($user->fresh()->wantsNotification(NotificationTopic::Security, NotificationChannel::Mail));
    }

    public function test_a_notification_is_delivered_only_on_the_wanted_channels(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $notification = new MemberJoined($team, $user);

        $this->assertSame(['mail', 'database', 'broadcast'], $notification->via($user));

        $user->setNotificationPreference(NotificationTopic::Team, NotificationChannel::Mail, false);
        $user->refresh();

        $this->assertSame(['database', 'broadcast'], $notification->via($user));
    }

    /**
     * An on-demand recipient has no preferences to consult, and refusing to
     * deliver to them would silently drop invitation mail.
     */
    public function test_a_recipient_without_preferences_gets_every_channel(): void
    {
        $team = Team::factory()->create();
        $notification = new MemberJoined($team, User::factory()->create());

        $anonymous = new \Illuminate\Notifications\AnonymousNotifiable;

        $this->assertSame(['mail', 'database', 'broadcast'], $notification->via($anonymous));
    }

    public function test_the_settings_page_saves_the_grid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.notifications')
            ->set('preferences.team.mail', false)
            ->set('preferences.billing.broadcast', false)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertFalse($user->wantsNotification(NotificationTopic::Team, NotificationChannel::Mail));
        $this->assertFalse($user->wantsNotification(NotificationTopic::Billing, NotificationChannel::Broadcast));
        $this->assertTrue($user->wantsNotification(NotificationTopic::Team, NotificationChannel::Broadcast));
    }

    public function test_the_settings_page_loads(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('notifications.edit'))->assertOk();
    }

    /**
     * The queue this notification goes on is not incidental: it is what
     * someone is waiting to see on the screen they are already looking at.
     */
    public function test_the_member_joined_notification_uses_the_high_priority_queue(): void
    {
        $notification = new MemberJoined(Team::factory()->create(), User::factory()->create());

        $this->assertSame('high', $notification->queue);
    }

    public function test_accepting_an_invitation_notifies_the_existing_members(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $team = Team::factory()->create(['is_personal' => false]);
        $owner = User::factory()->create();
        $joiner = User::factory()->create(['email' => 'joiner@example.com']);

        $team->memberships()->create(['user_id' => $owner->id]);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $invitation = \App\Models\TeamInvitation::create([
            'team_id' => $team->id,
            'email' => $joiner->email,
            'role' => TeamRole::Member,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(3),
        ]);

        $this->actingAs($joiner);

        // O aceite acontece no mount do componente; não há ação a chamar.
        Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation]);

        \Illuminate\Support\Facades\Notification::assertSentTo($owner, MemberJoined::class);

        // Quem entrou não é avisado da própria entrada.
        \Illuminate\Support\Facades\Notification::assertNotSentTo($joiner, MemberJoined::class);
    }
}
