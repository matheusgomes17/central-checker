<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\TeamRole;
use App\Enums\WebhookEvent;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebhookManagementTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->team = Team::factory()->create(['is_personal' => false]);
        $this->owner = $this->memberOf($this->team, TeamRole::Owner);

        $this->actingAs($this->owner);
    }

    public function test_the_page_loads(): void
    {
        $this->get(route('webhooks.edit'))->assertOk();
    }

    public function test_creating_an_endpoint_generates_a_secret_and_shows_it_once(): void
    {
        $component = Livewire::test('pages::settings.webhooks')
            ->set('url', 'https://example.com/hooks')
            ->set('events', [WebhookEvent::MemberJoined->value])
            ->call('create')
            ->assertHasNoErrors();

        $endpoint = WebhookEndpoint::query()->firstOrFail();

        $this->assertSame($this->team->id, $endpoint->team_id);
        $this->assertStringStartsWith('whsec_', $endpoint->secret);
        $component->assertSet('revealedId', $endpoint->id);

        // Criptografado, e não em texto puro: quem lê a linha da tabela não
        // deve conseguir assinar entregas.
        $this->assertDatabaseMissing('webhook_endpoints', ['secret' => $endpoint->secret]);
    }

    /**
     * Plain http would put the team's data on the wire in the clear. The
     * signature proves who sent the body; it does not hide it.
     */
    public function test_an_endpoint_must_be_https(): void
    {
        Livewire::test('pages::settings.webhooks')
            ->set('url', 'http://example.com/hooks')
            ->set('events', [WebhookEvent::MemberJoined->value])
            ->call('create')
            ->assertHasErrors('url');

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_an_endpoint_must_subscribe_to_something(): void
    {
        Livewire::test('pages::settings.webhooks')
            ->set('url', 'https://example.com/hooks')
            ->set('events', [])
            ->call('create')
            ->assertHasErrors('events');
    }

    public function test_an_unknown_event_is_refused(): void
    {
        Livewire::test('pages::settings.webhooks')
            ->set('url', 'https://example.com/hooks')
            ->set('events', ['tudo'])
            ->call('create')
            ->assertHasErrors('events.*');
    }

    public function test_the_list_only_shows_endpoints_for_this_team(): void
    {
        WebhookEndpoint::factory()->create(['team_id' => $this->team->id, 'url' => 'https://meu.example.com/h']);

        $other = Team::factory()->create(['is_personal' => false]);
        WebhookEndpoint::factory()->create(['team_id' => $other->id, 'url' => 'https://alheio.example.com/h']);

        Livewire::test('pages::settings.webhooks')
            ->assertSee('meu.example.com')
            ->assertDontSee('alheio.example.com');
    }

    /**
     * The secret signs every event about this team's members, so an id from
     * another team must not produce one.
     */
    public function test_another_teams_secret_is_not_revealed(): void
    {
        $other = Team::factory()->create(['is_personal' => false]);
        $theirs = WebhookEndpoint::factory()->create(['team_id' => $other->id]);

        Livewire::test('pages::settings.webhooks')
            ->call('reveal', $theirs->id)
            ->assertSet('revealedId', null)
            ->assertDontSee($theirs->secret);
    }

    public function test_another_teams_endpoint_cannot_be_deleted(): void
    {
        $other = Team::factory()->create(['is_personal' => false]);
        $theirs = WebhookEndpoint::factory()->create(['team_id' => $other->id]);

        Livewire::test('pages::settings.webhooks')->call('delete', $theirs->id);

        $this->assertDatabaseHas('webhook_endpoints', ['id' => $theirs->id]);
    }

    public function test_an_endpoint_can_be_deleted(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['team_id' => $this->team->id]);

        Livewire::test('pages::settings.webhooks')->call('delete', $endpoint->id);

        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
    }

    public function test_a_disabled_endpoint_can_be_switched_back_on(): void
    {
        $endpoint = WebhookEndpoint::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => false,
            'consecutive_failures' => WebhookEndpoint::FAILURE_LIMIT,
        ]);

        Livewire::test('pages::settings.webhooks')->call('reactivate', $endpoint->id);

        $endpoint->refresh();

        $this->assertTrue($endpoint->is_active);
        // Zerar o contador junto: reativar sem isso desligaria o endpoint na
        // primeira falha seguinte.
        $this->assertSame(0, $endpoint->consecutive_failures);
    }

    public function test_an_ordinary_member_cannot_manage_webhooks(): void
    {
        $member = $this->memberOf($this->team, TeamRole::Member);

        $this->actingAs($member);

        Livewire::test('pages::settings.webhooks')
            ->set('url', 'https://example.com/hooks')
            ->set('events', [WebhookEvent::MemberJoined->value])
            ->call('create')
            ->assertForbidden();

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    /**
     * Paying the invoice is not a reason to read the secret that signs every
     * event about the team's members.
     */
    public function test_the_billing_role_cannot_manage_webhooks(): void
    {
        $billing = $this->memberOf($this->team, TeamRole::Billing);

        $endpoint = WebhookEndpoint::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($billing);

        $component = Livewire::test('pages::settings.webhooks');

        $component->call('reveal', $endpoint->id)->assertForbidden();

        $this->assertNull($component->get('revealedId'));
    }

    private function memberOf(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, $role);
        $user->switchTeam($team);

        return $user;
    }
}
