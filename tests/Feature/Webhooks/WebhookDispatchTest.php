<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\TeamRole;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\TenantManager;
use App\Support\Webhooks\WebhookDispatcher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Queue::fake();
    }

    public function test_a_member_joining_queues_a_delivery(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $endpoint = $this->endpointFor($team, [WebhookEvent::MemberJoined]);

        $this->joinTeam($team);

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->endpoint->is($endpoint)
            && $job->body['type'] === WebhookEvent::MemberJoined->value);
    }

    public function test_a_member_leaving_queues_a_delivery(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $this->endpointFor($team, [WebhookEvent::MemberRemoved]);

        $user = $this->joinTeam($team);

        resolve(TenantManager::class)->forTenant($team, function () use ($team, $user): void {
            $team->memberships()->where('user_id', $user->id)->firstOrFail()->delete();
        });

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->body['type'] === WebhookEvent::MemberRemoved->value);
    }

    /**
     * The guard for the trap in emitting from the model: a query delete
     * removes the row without ever making a model, so no model event fires and
     * the customer is never told.
     */
    public function test_removing_a_member_through_the_interface_still_emits(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $this->endpointFor($team, [WebhookEvent::MemberRemoved]);

        $owner = $this->joinTeam($team, TeamRole::Owner);
        $member = $this->joinTeam($team);

        $owner->switchTeam($team);

        $this->actingAs($owner);

        \Livewire\Livewire::test('pages::teams.remove-member-modal', ['team' => $team])
            ->set('memberId', $member->id)
            ->call('removeMember');

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->body['type'] === WebhookEvent::MemberRemoved->value
            && $job->body['data']['user_id'] === $member->id);
    }

    public function test_an_endpoint_that_did_not_subscribe_gets_nothing(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $this->endpointFor($team, [WebhookEvent::SubscriptionChanged]);

        $this->joinTeam($team);

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    public function test_a_disabled_endpoint_gets_nothing(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $this->endpointFor($team, [WebhookEvent::MemberJoined], active: false);

        $this->joinTeam($team);

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    /**
     * The one that matters.
     *
     * Someone accepting an invitation to team B is browsing as team A at that
     * moment. A dispatcher that read the current tenant instead of the event's
     * team would post team B's membership to team A's endpoint, which is one
     * customer receiving another customer's events.
     */
    public function test_an_event_goes_to_its_own_teams_endpoints_and_not_the_current_ones(): void
    {
        $teamA = Team::factory()->create(['is_personal' => false, 'name' => 'A']);
        $teamB = Team::factory()->create(['is_personal' => false, 'name' => 'B']);

        $listeningA = $this->endpointFor($teamA, [WebhookEvent::MemberJoined]);
        $listeningB = $this->endpointFor($teamB, [WebhookEvent::MemberJoined]);

        // Navegando como o time A e entrando no time B.
        resolve(TenantManager::class)->set($teamA);

        $this->joinTeam($teamB);

        Queue::assertPushed(DeliverWebhook::class, 1);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->endpoint->is($listeningB));
        Queue::assertNotPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->endpoint->is($listeningA));
    }

    /**
     * The invitation token is the credential that gets someone into the team,
     * so it must not be mailed to whatever server the customer configured.
     */
    public function test_the_invitation_payload_never_carries_the_token(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $this->endpointFor($team, [WebhookEvent::InvitationCreated]);

        $owner = $this->joinTeam($team, TeamRole::Owner);

        $invitation = resolve(TenantManager::class)->forTenant($team, fn () => $team->invitations()->create([
            'email' => 'convidado@example.com',
            'role' => TeamRole::Member,
            'code' => 'segredo-do-convite',
            'invited_by' => $owner->id,
            'expires_at' => now()->addWeek(),
        ]));

        Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use ($invitation): bool {
            $encoded = json_encode($job->body, JSON_THROW_ON_ERROR);

            return ! str_contains($encoded, $invitation->code)
                && $job->body['data']['email'] === 'convidado@example.com';
        });
    }

    public function test_the_dispatcher_is_a_no_op_without_endpoints(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);

        resolve(WebhookDispatcher::class)->send(WebhookEvent::MemberJoined, $team, []);

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    /**
     * @param  array<int, WebhookEvent>  $events
     */
    private function endpointFor(Team $team, array $events, bool $active = true): WebhookEndpoint
    {
        return WebhookEndpoint::factory()
            ->subscribedTo($events)
            ->create(['team_id' => $team->id, 'is_active' => $active]);
    }

    private function joinTeam(Team $team, TeamRole $role = TeamRole::Member): User
    {
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, $role);

        return $user;
    }
}
