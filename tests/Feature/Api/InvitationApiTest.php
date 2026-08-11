<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // A API deixou de ser gratuita: sem assinatura do Pro, tokenFor()
        // devolveria um time que o middleware de recurso recusaria com 402
        // antes de chegar ao que cada teste aqui investiga.
        config(['billing.plans.pro.price_id' => 'price_pro']);

        Notification::fake();
    }

    public function test_an_invitation_can_be_created(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $this->withToken($token)
            ->postJson('/api/v1/invitations', ['email' => 'novo@example.com', 'role' => 'member'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'novo@example.com');

        $this->assertDatabaseHas('team_invitations', [
            'team_id' => $team->id,
            'email' => 'novo@example.com',
        ]);
    }

    /**
     * The code is the credential that gets someone into the team. An API list
     * of invitations must not be a list of keys.
     */
    public function test_the_code_never_appears_in_a_response(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $created = $this->withToken($token)
            ->postJson('/api/v1/invitations', ['email' => 'novo@example.com', 'role' => 'member'])
            ->assertCreated();

        $invitation = TeamInvitation::query()->firstOrFail();

        $this->assertStringNotContainsString($invitation->code, $created->getContent() ?: '');

        $listed = $this->withToken($token)->getJson('/api/v1/invitations')->assertOk();

        $this->assertStringNotContainsString($invitation->code, $listed->getContent() ?: '');
    }

    /**
     * A team has one owner and it changes by transfer, so a write token must
     * not be able to mint a second one.
     */
    public function test_an_invitation_cannot_grant_ownership(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $this->withToken($token)
            ->postJson('/api/v1/invitations', ['email' => 'novo@example.com', 'role' => 'owner'])
            ->assertStatus(422);

        $this->assertSame(0, TeamInvitation::query()->count());
    }

    public function test_the_same_address_is_not_invited_twice(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $payload = ['email' => 'novo@example.com', 'role' => 'member'];

        $this->withToken($token)->postJson('/api/v1/invitations', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/invitations', $payload)->assertStatus(422);
    }

    public function test_the_list_only_shows_this_teams_invitations(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $this->invitationFor($team, 'meu@example.com', $user);

        $other = Team::factory()->create(['is_personal' => false]);
        $otherOwner = User::factory()->create();
        $other->memberships()->create(['user_id' => $otherOwner->id]);
        $this->invitationFor($other, 'alheio@example.com', $otherOwner);

        $response = $this->withToken($token)->getJson('/api/v1/invitations')->assertOk();

        /** @var array<int, array{email: string}> $data */
        $data = $response->json('data');

        $this->assertSame(['meu@example.com'], array_column($data, 'email'));
    }

    public function test_an_invitation_from_another_team_cannot_be_cancelled(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $other = Team::factory()->create(['is_personal' => false]);
        $otherOwner = User::factory()->create();
        $other->memberships()->create(['user_id' => $otherOwner->id]);
        $theirs = $this->invitationFor($other, 'alheio@example.com', $otherOwner);

        $this->withToken($token)
            ->deleteJson('/api/v1/invitations/'.$theirs->id)
            ->assertNotFound();

        $this->assertDatabaseHas('team_invitations', ['id' => $theirs->id]);
    }

    public function test_an_invitation_can_be_cancelled(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $invitation = $this->invitationFor($team, 'meu@example.com', $user);

        $this->withToken($token)
            ->deleteJson('/api/v1/invitations/'.$invitation->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    public function test_a_read_token_cannot_invite(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner, ApiAbility::Read);

        $this->withToken($token)
            ->postJson('/api/v1/invitations', ['email' => 'novo@example.com', 'role' => 'member'])
            ->assertForbidden();
    }

    /**
     * The ability is a ceiling, not a permission: a write token still obeys
     * what the person is allowed to do.
     */
    public function test_an_ordinary_member_cannot_invite(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Member);

        $this->withToken($token)
            ->postJson('/api/v1/invitations', ['email' => 'novo@example.com', 'role' => 'member'])
            ->assertForbidden();

        $this->assertSame(0, TeamInvitation::query()->count());
    }

    private function invitationFor(Team $team, string $email, User $invitedBy): TeamInvitation
    {
        return $team->invitations()->create([
            'email' => $email,
            'role' => TeamRole::Member,
            'invited_by' => $invitedBy->getKey(),
            'expires_at' => now()->addWeek(),
        ]);
    }

    /**
     * @return array{User, Team, string}
     */
    private function tokenFor(TeamRole $role, ApiAbility $ability = ApiAbility::Write): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['is_personal' => false]);

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, $role);

        $team->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_'.fake()->uuid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        $token = $user->createToken('teste', $ability->implies());

        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $team->id]);

        return [$user, $team, $token->plainTextToken];
    }
}
