<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Modules\Carteira\Models\Wallet;
use Tests\TestCase;

/**
 * The product routes carry no team slug: `TenantManager::current()` already
 * resolves lazily from `auth()->user()->currentTeam` (see
 * app/Support/TenantManager.php), so `/dashboard` and `/checker/*` can drop
 * the `{current_team}` prefix entirely and still land on the right tenant.
 * Switching teams is now exclusively the team switcher's job — it updates
 * `current_team_id` and the request-scoped tenant, not the URL.
 */
class TeamlessUrlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_resolves_the_users_current_team_without_a_slug(): void
    {
        $user = User::factory()->create();
        $team = $user->personalTeam();

        Wallet::factory()->withBalance(543210)->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee((string) Number::currency(5432.10, in: 'BRL', locale: 'pt_BR'));

        expect(route('dashboard'))
            ->not->toContain($team->slug)
            ->toEndWith('/dashboard');
    }

    public function test_the_product_shows_only_the_current_teams_data_without_a_slug(): void
    {
        $user = User::factory()->create();
        $teamA = $user->personalTeam();
        $teamB = Team::factory()->create();
        $teamB->members()->attach($user);
        $user->assignTeamRole($teamB, TeamRole::Member);

        Wallet::factory()->withBalance(111100)->create(['team_id' => $teamA->id]);
        Wallet::factory()->withBalance(222200)->create(['team_id' => $teamB->id]);

        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee((string) Number::currency(1111.00, in: 'BRL', locale: 'pt_BR'))
            ->assertDontSee((string) Number::currency(2222.00, in: 'BRL', locale: 'pt_BR'));

        $user->switchTeam($teamB);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee((string) Number::currency(2222.00, in: 'BRL', locale: 'pt_BR'))
            ->assertDontSee((string) Number::currency(1111.00, in: 'BRL', locale: 'pt_BR'));
    }

    public function test_checker_routes_have_no_team_segment(): void
    {
        expect(route('checker.executar', absolute: false))->toBe('/checker/executar')
            ->and(route('checker.carteira', absolute: false))->toBe('/checker/carteira')
            ->and(route('checker.planos', absolute: false))->toBe('/checker/planos')
            ->and(route('checker.historico', absolute: false))->toBe('/checker/historico');
    }
}
