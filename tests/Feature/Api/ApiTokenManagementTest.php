<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Enums\SystemRole;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ApiTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->team = Team::factory()->create(['is_personal' => false]);
        PlanSubscription::factory()->active()->create(['team_id' => $this->team->id, 'plan' => 'pro']);
        $this->user = User::factory()->create();

        $this->team->memberships()->create(['user_id' => $this->user->id]);
        $this->user->assignTeamRole($this->team, TeamRole::Owner);
        $this->user->switchTeam($this->team);

        $this->actingAs($this->user);
    }

    public function test_the_page_loads(): void
    {
        $this->get(route('api-tokens.edit'))->assertOk();
    }

    /**
     * Disappearing without a word is what generates the support ticket. The
     * screen says which plan includes the API instead of hiding the form.
     */
    public function test_a_team_without_the_api_feature_is_told_why(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('api-tokens.edit'))
            ->assertOk()
            ->assertSee(__('Your :plan plan does not include :feature.', [
                'plan' => 'Free',
                'feature' => 'api',
            ]))
            ->assertDontSee('data-test="create-token"', escape: false);
    }

    /**
     * The screen asks Pennant, not the plan directly, for the same reason the
     * API middleware does: support can grant the capability to one free team
     * without editing the plan config, and the form has to come back for it.
     */
    public function test_a_one_off_grant_brings_the_form_back(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Feature::for($team)->activate('api');

        $this->actingAs($user)
            ->get(route('api-tokens.edit'))
            ->assertOk()
            ->assertSee('data-test="create-token"', escape: false);
    }

    /**
     * Hiding the form is not access control: the component's own action is
     * still reachable straight through the Livewire protocol, skipping whatever
     * the view chose to render. The server has to refuse it too.
     */
    public function test_a_team_without_the_api_feature_cannot_create_a_token_through_the_component(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.api-tokens')
            ->set('name', 'CI')
            ->set('ability', ApiAbility::Read->value)
            ->call('createToken')
            ->assertHasErrors('name');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Losing the feature cannot mean losing the only place to revoke what was
     * issued while the team still had it: tokens created under a better plan
     * keep existing after a downgrade, and the screen has to let them go.
     */
    public function test_a_team_without_the_api_feature_can_still_see_and_revoke_its_tokens(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $user->createToken('CI', ['read']);
        $apiToken = ApiToken::query()->latest('id')->firstOrFail();

        // update() no model dispararia mass assignment, e team_id está fora
        // do $fillable herdado do PersonalAccessToken do Sanctum — por isso a
        // escrita direta pela query, como o resto deste arquivo já faz.
        ApiToken::query()->whereKey($apiToken->id)->update(['team_id' => $team->id]);

        $this->actingAs($user)
            ->get(route('api-tokens.edit'))
            ->assertOk()
            ->assertSee('CI')
            ->assertSee('data-test="revoke-token-'.$apiToken->id.'"', escape: false);

        Livewire::test('pages::settings.api-tokens')
            ->call('revoke', $apiToken->id);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $apiToken->id]);
    }

    /**
     * A token created without a team would be refused on its first call, so
     * the team has to be written even though Sanctum's own createToken knows
     * nothing about the column.
     */
    public function test_creating_a_token_stamps_it_with_the_current_team(): void
    {
        Livewire::test('pages::settings.api-tokens')
            ->set('name', 'CI')
            ->set('ability', ApiAbility::Read->value)
            ->call('createToken')
            ->assertHasNoErrors();

        $token = ApiToken::query()->latest('id')->firstOrFail();

        $this->assertSame('CI', $token->name);
        $this->assertSame($this->team->id, $token->team_id);
        $this->assertSame($this->user->id, $token->tokenable_id);
    }

    public function test_the_plain_token_is_shown_once_and_not_stored(): void
    {
        $component = Livewire::test('pages::settings.api-tokens')
            ->set('name', 'CI')
            ->call('createToken');

        $plain = $component->get('plainTextToken');

        $this->assertIsString($plain);
        $this->assertNotEmpty($plain);

        // O que fica no banco é o hash. Guardar o texto puro para reexibir
        // seria desfazer o motivo de haver hash.
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plain]);

        $component->call('dismissToken')->assertSet('plainTextToken', null);
    }

    public function test_a_write_token_also_carries_read(): void
    {
        Livewire::test('pages::settings.api-tokens')
            ->set('name', 'Deploy')
            ->set('ability', ApiAbility::Write->value)
            ->call('createToken');

        $token = ApiToken::query()->latest('id')->firstOrFail();

        $this->assertEqualsCanonicalizing(['read', 'write'], $token->abilities);
    }

    public function test_the_list_only_shows_tokens_for_this_team(): void
    {
        $otherTeam = Team::factory()->create(['is_personal' => false]);
        $otherTeam->memberships()->create(['user_id' => $this->user->id]);
        $this->user->assignTeamRole($otherTeam, TeamRole::Owner);

        $mine = $this->user->createToken('deste time', ['read']);
        ApiToken::query()->whereKey($mine->accessToken->getKey())->update(['team_id' => $this->team->id]);

        $theirs = $this->user->createToken('do outro time', ['read']);
        ApiToken::query()->whereKey($theirs->accessToken->getKey())->update(['team_id' => $otherTeam->id]);

        Livewire::test('pages::settings.api-tokens')
            ->assertSee('deste time')
            ->assertDontSee('do outro time');
    }

    public function test_a_token_can_be_revoked(): void
    {
        $token = $this->user->createToken('CI', ['read']);
        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $this->team->id]);

        Livewire::test('pages::settings.api-tokens')
            ->call('revoke', $token->accessToken->getKey());

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
    }

    /**
     * Revoking is scoped to the owner: a token id belonging to another person
     * must not be revocable from here.
     */
    public function test_another_persons_token_cannot_be_revoked(): void
    {
        $stranger = User::factory()->create();
        $this->team->memberships()->create(['user_id' => $stranger->id]);
        $stranger->assignTeamRole($this->team, TeamRole::Member);

        $token = $stranger->createToken('deles', ['read']);
        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $this->team->id]);

        Livewire::test('pages::settings.api-tokens')
            ->call('revoke', $token->accessToken->getKey());

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
    }

    public function test_a_revoked_token_stops_working_immediately(): void
    {
        $token = $this->user->createToken('CI', ['read']);
        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $this->team->id]);

        $this->apiCall($token->plainTextToken)->assertOk();

        Livewire::test('pages::settings.api-tokens')->call('revoke', $token->accessToken->getKey());

        $this->apiCall($token->plainTextToken)->assertUnauthorized();
    }

    /**
     * Calls the API with the token and nothing else.
     *
     * The session set up by actingAs() authenticates against auth:sanctum too,
     * so without dropping the guards these assertions would pass with any
     * token at all — including a revoked one.
     */
    /**
     * @return TestResponse<Response>
     */
    private function apiCall(string $token): TestResponse
    {
        auth()->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/team');
    }

    /**
     * Documentation for an internal API is not public, and the rule is the
     * same as the operator panel so there is only one answer to "who is an
     * administrator".
     */
    public function test_the_api_documentation_is_closed_to_ordinary_users(): void
    {
        $this->get('/docs/api')->assertForbidden();
    }

    public function test_the_api_documentation_opens_for_a_super_admin(): void
    {
        $this->user->setSystemRole(SystemRole::SuperAdmin);
        $this->user->refresh();

        $this->actingAs($this->user);

        $this->get('/docs/api')->assertOk();
    }
}
