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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The edges of the ability middleware, which the tests of the API cannot reach.
 *
 * The application only ever passes `read` or `write`, so the branch that
 * refuses an ability nobody defined is unreachable from the real routes — and
 * unreachable code that guards a mistake is exactly the code that stops
 * working without anyone noticing. These routes exist to reach it.
 */
class TokenAbilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // A API deixou de ser gratuita: sem assinatura do Pro, o time
        // resolvido para o pedido cairia em 402 antes de chegar ao que este
        // arquivo investiga.
        config(['billing.plans.pro.price_id' => 'price_pro']);

        Route::middleware(['api', 'auth:sanctum'])->group(function (): void {
            Route::get('probe/unknown-ability', fn (): string => 'ok')->middleware('ability:desconhecida');
            Route::get('probe/write-only', fn (): string => 'ok')->middleware('ability:write');
        });
    }

    /**
     * A typo in a route definition must not read as "no restriction". Failing
     * loudly on our side is the only outcome that gets it fixed.
     */
    public function test_an_ability_the_application_does_not_define_is_refused(): void
    {
        $response = $this->withToken($this->token(ApiAbility::Write))
            ->getJson('probe/unknown-ability')
            ->assertServerError();

        // O 500 sozinho não distingue "recusamos de propósito" de "estourou ao
        // usar um valor nulo" — as duas coisas respondem 500.
        $this->assertStringContainsString('Habilidade desconhecida', $response->getContent() ?: '');
    }

    /**
     * A first-party session goes through here too, and what it carries is a
     * TransientToken, not an ApiToken. Treating anything non-null as an API
     * token would break the API for our own front end.
     */
    public function test_a_first_party_session_passes_through(): void
    {
        $user = User::factory()->create();

        // Sem token, o tenant cai de volta no time atual do usuário — o
        // pessoal que o factory já criou — e ele também responde ao
        // middleware de recurso de plano.
        $user->currentTeam->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_'.fake()->uuid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        $this->actingAs($user);

        $this->getJson('/api/v1/user')->assertOk();
    }

    /**
     * The refusal says which ability was missing. A 403 with no name sends the
     * integrator to guess among the abilities their token does have.
     */
    public function test_the_refusal_names_the_ability_that_was_missing(): void
    {
        $response = $this->withToken($this->token(ApiAbility::Read))
            ->getJson('probe/write-only')
            ->assertForbidden();

        $this->assertStringContainsString(ApiAbility::Write->value, $response->getContent() ?: '');
    }

    private function token(ApiAbility $ability): string
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['is_personal' => false]);

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, TeamRole::Owner);

        $token = $user->createToken('teste', $ability->implies());

        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $team->id]);

        return $token->plainTextToken;
    }
}
