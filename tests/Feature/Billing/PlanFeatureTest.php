<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Exceptions\PlanFeatureException;
use App\Http\Middleware\SetTenantFromToken;
use App\Models\ApiToken;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * The API is sold with the Pro plan and was available to everyone.
 *
 * The middleware order is a constraint and not a detail: the feature is scoped
 * to the team, and the team comes from the token. Asked before the tenant
 * exists, it answers about nobody.
 */
class PlanFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_free_team_cannot_reach_the_api(): void
    {
        [$team, $token] = $this->tokenFor(subscribed: false);

        $this->withToken($token)
            ->getJson('/api/v1/team')
            ->assertStatus(402)
            ->assertJsonPath('title', 'plan_feature_required');

        $this->assertFalse(TeamBilling::for($team)->plan()->hasFeature('api'));
    }

    public function test_a_paying_team_reaches_the_api(): void
    {
        [, $token] = $this->tokenFor(subscribed: true);

        $this->withToken($token)->getJson('/api/v1/team')->assertOk();
    }

    /**
     * The decision has to be made per request, not once and for all.
     *
     * Pennant's database store writes the first answer down and returns it
     * forever after. A team refused on the free plan and then charged for the
     * Pro one has to be let in on the very next call — anything else is a
     * customer who paid and got nothing.
     */
    public function test_a_refused_team_is_let_in_as_soon_as_it_subscribes(): void
    {
        [$team, $token] = $this->tokenFor(subscribed: false);

        $this->withToken($token)->getJson('/api/v1/team')->assertStatus(402);

        $this->subscribe($team);
        $this->asANewRequestWould();

        $this->withToken($token)->getJson('/api/v1/team')->assertOk();
    }

    /**
     * The same staleness in the direction that costs money: an answer of "yes"
     * written down while the team was paying would outlive the subscription
     * that earned it.
     */
    public function test_an_accepted_team_is_refused_once_its_subscription_is_over(): void
    {
        [$team, $token] = $this->tokenFor(subscribed: true);

        $this->withToken($token)->getJson('/api/v1/team')->assertOk();

        PlanSubscription::query()->where('team_id', $team->id)->update(['current_period_end' => now()->subDay()]);
        $this->asANewRequestWould();

        $this->withToken($token)->getJson('/api/v1/team')->assertStatus(402);
    }

    /**
     * A refusal that names the plan the team is already on tells them what
     * they just found out. The next step is the plan that sells the thing, and
     * the cheapest one that does is the honest answer to "so which one".
     */
    public function test_the_refusal_names_the_plan_that_sells_the_feature(): void
    {
        [, $token] = $this->tokenFor(subscribed: false);

        $this->withToken($token)
            ->getJson('/api/v1/team')
            ->assertStatus(402)
            ->assertJsonPath('detail', 'The api feature comes with the Pro plan.');
    }

    /**
     * A capability nobody sells has no upgrade to point at, and the message
     * has to stop rather than name a plan that does not exist.
     */
    public function test_the_refusal_still_reads_when_no_plan_sells_the_feature(): void
    {
        Config::set('billing.plans.pro.features', []);
        Config::set('billing.plans.business.features', []);

        $this->assertSame(
            'The api feature is not part of any plan.',
            PlanFeatureException::required('api')->getMessage(),
        );
    }

    /**
     * The support story the feature flags were built for: someone was promised
     * a capability their plan does not include, and the alternative is editing
     * the plan config for one account.
     */
    public function test_an_override_for_one_team_wins_over_the_plan(): void
    {
        [$team, $token] = $this->tokenFor(subscribed: false);

        Feature::for($team)->activate('api');

        $this->withToken($token)->getJson('/api/v1/team')->assertOk();
    }

    /**
     * If the check ran before the tenant was resolved it would ask about no
     * team at all, and a route that answers 402 for a paying customer is worse
     * than one that never checked.
     *
     * gatherMiddleware() lists the route's own middleware without expanding
     * groups, so SetTenantFromToken — appended to the `api` group in
     * bootstrap/app.php — never shows up in it literally; it stays folded
     * inside the raw 'api' entry. gatherRouteMiddleware() is the resolved,
     * group-expanded list the kernel actually runs, which is the order this
     * test needs to pin down.
     */
    public function test_the_feature_check_runs_after_the_tenant_is_resolved(): void
    {
        $route = Route::getRoutes()->getByName('api.team.show');

        $this->assertNotNull($route, 'A rota api.team.show não existe.');

        $middleware = resolve(Router::class)->gatherRouteMiddleware($route);

        $tenant = array_search(SetTenantFromToken::class, $middleware, true);
        $feature = array_search('App\Http\Middleware\EnsurePlanFeature:api', $middleware, true);

        $this->assertIsInt($tenant, 'O middleware de tenant não está na rota.');
        $this->assertIsInt($feature, 'O middleware de recurso de plano não está na rota.');
        $this->assertLessThan($feature, $tenant, 'O recurso de plano é verificado antes de o tenant existir.');
    }

    public function test_the_catalog_only_names_features_the_product_delivers(): void
    {
        /** @var array<string, array{features: array<int, string>}> $plans */
        $plans = Config::array('billing.plans');

        foreach ($plans as $key => $plan) {
            $this->assertSame(
                [],
                array_diff($plan['features'], ['api']),
                "O plano [{$key}] anuncia recurso que o produto não verifica.",
            );
        }
    }

    /**
     * @return array{Team, string}
     */
    private function tokenFor(bool $subscribed): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['is_personal' => false]);

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, TeamRole::Owner);

        if ($subscribed) {
            $this->subscribe($team);
        }

        $token = $user->createToken('teste', ApiAbility::Read->implies());
        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $team->id]);

        return [$team->refresh(), $token->plainTextToken];
    }

    /**
     * Drops what only survives because the test reuses one application.
     *
     * The guard keeps the user it resolved, and with it the token and the team
     * the token loaded, relations and all. A second real request re-reads every
     * one of those from the database; without this, a test making two calls
     * would be measuring a Team frozen at the first one, and would report a
     * stale subscription as if it were a stale feature flag.
     *
     * Pennant's table is deliberately left alone — the point of these tests is
     * that whatever it has written down does not decide the answer.
     */
    private function asANewRequestWould(): void
    {
        Auth::forgetGuards();
    }

    private function subscribe(Team $team): void
    {
        PlanSubscription::factory()->active()->create(['team_id' => $team->id, 'plan' => 'pro']);
    }
}
