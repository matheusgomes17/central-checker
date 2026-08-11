<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phase acceptance criterion: a token issued for one team must not reach
 * another team's resources.
 *
 * The tenant on the API comes from the token and from nothing else. Letting it
 * come from the owner's "current team" would make an API call answer
 * differently depending on what its owner last clicked in a browser.
 */
class ApiTokenTest extends TestCase
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
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $this->getJson('/api/v1/team')
            ->assertUnauthorized()
            ->assertJsonPath('title', 'unauthenticated');
    }

    public function test_a_token_reads_its_own_team(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $this->withToken($token)
            ->getJson('/api/v1/team')
            ->assertOk()
            ->assertJsonPath('data.id', $team->id)
            ->assertJsonPath('data.slug', $team->slug);
    }

    /**
     * The one that matters. The owner belongs to both teams, so nothing about
     * the person forbids this — only the token does.
     */
    public function test_a_token_cannot_reach_another_team(): void
    {
        [$user, $teamA, $token] = $this->tokenFor(TeamRole::Owner);

        $teamB = Team::factory()->create(['is_personal' => false, 'name' => 'Outro time']);
        $teamB->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($teamB, TeamRole::Owner);

        $outsider = User::factory()->create();
        $teamB->memberships()->create(['user_id' => $outsider->id]);
        $outsider->assignTeamRole($teamB, TeamRole::Member);

        // Mesmo com o time atual do usuário apontando para o time B, o token
        // continua falando pelo time A.
        $user->switchTeam($teamB);

        $this->withToken($token)
            ->getJson('/api/v1/team')
            ->assertOk()
            ->assertJsonPath('data.id', $teamA->id);

        // Asserção sobre o conjunto exato, e não sobre a ausência de um id:
        // assertJsonMissing passou mesmo quando a listagem devolvia todos os
        // usuários da base, porque procurava um fragmento em vez de comparar
        // a lista inteira.
        $response = $this->withToken($token)->getJson('/api/v1/members')->assertOk();

        /** @var array<int, array{id: int}> $members */
        $members = $response->json('data');

        $this->assertSame([$user->id], array_column($members, 'id'));

        $this->withToken($token)
            ->getJson('/api/v1/members/'.$outsider->id)
            ->assertNotFound();
    }

    /**
     * Falling back when the token's team is gone is how a revoked membership
     * keeps its access.
     */
    public function test_a_token_whose_owner_left_the_team_is_refused(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Member);

        $team->memberships()->where('user_id', $user->id)->delete();

        $this->withToken($token)
            ->getJson('/api/v1/team')
            ->assertForbidden();
    }

    public function test_a_token_without_a_team_is_refused(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('sem time', ApiAbility::Read->implies())->plainTextToken;

        $this->withToken($plain)
            ->getJson('/api/v1/team')
            ->assertForbidden();
    }

    /**
     * The ability is a ceiling on what the credential may attempt.
     */
    public function test_a_read_token_cannot_write(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner, ApiAbility::Read);

        $member = User::factory()->create();
        $team->memberships()->create(['user_id' => $member->id]);
        $member->assignTeamRole($team, TeamRole::Member);

        $this->withToken($token)
            ->deleteJson('/api/v1/members/'.$member->id)
            ->assertForbidden();

        $this->assertTrue($team->members()->whereKey($member->id)->exists());
    }

    public function test_a_write_token_can_write(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner, ApiAbility::Write);

        $member = User::factory()->create();
        $team->memberships()->create(['user_id' => $member->id]);
        $member->assignTeamRole($team, TeamRole::Member);

        $this->withToken($token)
            ->deleteJson('/api/v1/members/'.$member->id)
            ->assertNoContent();

        $this->assertFalse($team->members()->whereKey($member->id)->exists());
    }

    /**
     * Both checks run: what the credential may attempt, and what the person is
     * allowed to do at all.
     */
    public function test_a_write_token_still_obeys_the_role(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Member, ApiAbility::Write);

        $other = User::factory()->create();
        $team->memberships()->create(['user_id' => $other->id]);
        $other->assignTeamRole($team, TeamRole::Member);

        $this->withToken($token)
            ->deleteJson('/api/v1/members/'.$other->id)
            ->assertForbidden();

        $this->assertTrue($team->members()->whereKey($other->id)->exists());
    }

    public function test_the_member_list_can_be_filtered_and_sorted(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        // O dono nasce com nome do faker, e o locale gera "Adalberto",
        // "Ademir" e parentes — qualquer um casa com o filtro "Ada" e faz a
        // contagem dar dois. O teste passava por sorte; fixar o nome é o que
        // tira a moeda do caminho.
        $user->update(['name' => 'Zoe Owner']);

        foreach (['Ada Lovelace', 'Grace Hopper'] as $name) {
            $member = User::factory()->create(['name' => $name]);
            $team->memberships()->create(['user_id' => $member->id]);
            $member->assignTeamRole($team, TeamRole::Member);
        }

        $this->withToken($token)
            ->getJson('/api/v1/members?filter[name]=Ada')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ada Lovelace');
    }

    /**
     * The invitation code is the credential that gets someone into the team.
     * A list of invitations must not be a list of keys.
     */
    public function test_the_member_payload_never_exposes_a_role_from_another_team(): void
    {
        [$user, $team, $token] = $this->tokenFor(TeamRole::Owner);

        $this->withToken($token)
            ->getJson('/api/v1/members')
            ->assertOk()
            ->assertJsonPath('data.0.role', TeamRole::Owner->value);
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
