<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\TeamRole;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Modules\Carteira\Models\Wallet;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Tests\TestCase;

/**
 * The product dashboard replaces the placeholder screen with real numbers:
 * consultas in the last 30 days, success rate, wallet balance and the plan
 * timer. See resources/views/pages/⚡dashboard.blade.php for the honesty rule
 * around the success rate (null, never a fake 0%, when nothing ran).
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_sees_consultas_balance_and_plan_name(): void
    {
        [$user, $team] = $this->memberOf();

        PlanSubscription::factory()->create([
            'team_id' => $team->id,
            'plan' => 'pro',
        ]);

        Wallet::factory()->withBalance(543210)->create(['team_id' => $team->id]);

        $check = Check::factory()->create();
        CheckExecution::factory()->count(2)->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
        ]);
        CheckExecution::factory()->count(2)->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Failed,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('4') // total de consultas no período
            ->assertSee((string) Number::currency(5432.10, in: 'BRL', locale: 'pt_BR'))
            ->assertSee('Pro');
    }

    public function test_a_member_sees_a_link_to_the_carteira(): void
    {
        [$user, $team] = $this->memberOf();

        Wallet::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertSee(route('checker.carteira'), false);
    }

    public function test_a_member_sees_a_link_to_the_planos(): void
    {
        [$user, $team] = $this->memberOf();

        Wallet::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertSee(route('checker.planos'), false);
    }

    public function test_a_member_sees_a_link_to_the_historico(): void
    {
        [$user, $team] = $this->memberOf();

        Wallet::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertSee(route('checker.historico'), false);
    }

    public function test_a_member_sees_a_link_to_the_profile(): void
    {
        [$user, $team] = $this->memberOf();

        Wallet::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertSee(route('profile.edit'), false);
    }

    public function test_a_team_without_executions_shows_the_empty_state_not_a_fake_rate(): void
    {
        [$user, $team] = $this->memberOf();

        Wallet::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        // "0,0%" é o que um cálculo ingênuo de 0/0 mostraria — o texto exato
        // que a regra da honestidade proíbe. Não usamos "0%" sozinho: aparece
        // como substring inofensiva em classes Tailwind ("transparent_10%").
        $response->assertOk()
            ->assertSee('—')
            ->assertDontSee('0,0%');
    }

    /**
     * Plan-timer math tripwire: a 45-day period (30 days elapsed, 15 left)
     * must render the correct days-left label and fill the bar to
     * 15/45 ≈ 33%. Both numbers are correct-by-reading in the component but
     * were never exercised by a test — this pins the arithmetic down.
     */
    public function test_the_plan_timer_shows_days_left_and_period_fraction(): void
    {
        [$user, $team] = $this->memberOf();

        PlanSubscription::factory()->create([
            'team_id' => $team->id,
            'plan' => 'pro',
            'current_period_start' => now()->subDays(30),
            'current_period_end' => now()->addDays(15),
        ]);

        Wallet::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        // "expira em 15 dias" — texto do x-fire.plan-timer para daysLeft=15.
        $response->assertOk()
            ->assertSee('expira em 15 dias')
            ->assertSee('aria-valuenow="33"', false);
    }

    /**
     * There is no team slug in the URL anymore for the middleware to refuse,
     * so the only way left to reach the dashboard without a team is to have
     * none as current — the edge case EnsureTeamMembership still 403s on.
     */
    public function test_a_user_without_a_current_team_cannot_view_the_dashboard(): void
    {
        $user = User::factory()->create();
        // A relação currentTeam já foi carregada pelo switchTeam() da factory
        // — sem descartá-la, update() muda a coluna mas o objeto em memória
        // ainda responde com o time antigo.
        $user->update(['current_team_id' => null]);
        $user->unsetRelation('currentTeam');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Team}
     */
    private function memberOf(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Member);
        $user->switchTeam($team);

        return [$user, $team];
    }
}
