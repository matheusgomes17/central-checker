<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Widgets\OperationsOverview;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Carteira\Models\Wallet;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Tests\TestCase;

/**
 * Like ProviderHealth and the other cross-team audit screens, the sharpest
 * edge here is acrossTenants() on every aggregate: CheckExecution and Wallet
 * are both BelongsToTenant, and the admin panel runs with no ambient tenant —
 * a naive count()/sum() reads 0 no matter what is seeded.
 */
class OperationsOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());
    }

    public function test_the_widget_renders(): void
    {
        Livewire::test(OperationsOverview::class)->assertOk();
    }

    public function test_it_counts_executions_across_teams(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        CheckExecution::factory()->create(['team_id' => $teamA->id, 'created_at' => now()]);
        CheckExecution::factory()->count(3)->create(['team_id' => $teamB->id, 'created_at' => now()]);

        // Ontem: fora do "hoje", não deve entrar na contagem.
        CheckExecution::factory()->create(['team_id' => $teamA->id, 'created_at' => now()->subDay()]);

        $component = Livewire::test(OperationsOverview::class);
        $component->assertOk();

        // Dígito isolado via assertSee é ambíguo aqui: os ícones embutidos
        // nas outras stats carregam paths de vetor cheios de números
        // (viewBox, coordenadas) que colidem por acaso com dígitos pequenos.
        // Extrair o valor do bloco certo pelo rótulo é o que de fato prova o
        // acrossTenants — sem ele o teste passava "por sorte".
        $this->assertSame('4', $this->statValue($component->html(), 'Consultas (hoje)'));
    }

    public function test_the_success_rate_is_computed_over_the_window(): void
    {
        $check = Check::factory()->create();

        CheckExecution::factory()->count(3)->create([
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
            'created_at' => now()->subDays(5),
        ]);

        CheckExecution::factory()->create([
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Failed,
            'created_at' => now()->subDays(10),
        ]);

        // Fora da janela de 30 dias: não deve entrar na conta.
        CheckExecution::factory()->create([
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
            'created_at' => now()->subDays(40),
        ]);

        $component = Livewire::test(OperationsOverview::class);
        $component->assertOk();

        $this->assertSame('75%', $this->statValue($component->html(), 'Taxa de sucesso (30 dias)'));
    }

    public function test_the_success_rate_shows_a_dash_when_there_is_nothing_to_measure(): void
    {
        Livewire::test(OperationsOverview::class)
            ->assertOk()
            ->assertSee('—')
            ->assertSee('Sem consultas');
    }

    public function test_the_aggregate_balance_sums_all_wallets(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $teamC = Team::factory()->create();

        Wallet::factory()->create(['team_id' => $teamA->id, 'balance_cents' => 1000]);
        Wallet::factory()->create(['team_id' => $teamB->id, 'balance_cents' => 2000]);
        Wallet::factory()->create(['team_id' => $teamC->id, 'balance_cents' => 3000]);

        // Sem o símbolo: o ICU insere espaço não separável entre a moeda e o
        // número, que sai escapado no HTML e nunca casaria literalmente.
        Livewire::test(OperationsOverview::class)
            ->assertOk()
            ->assertSee('60,00');
    }

    /**
     * The value shown under a given stat label, read straight out of the
     * rendered markup instead of guessed at with assertSee — a bare digit
     * assertion would also match the numbers baked into the other stats'
     * icon vector paths.
     */
    private function statValue(string $html, string $label): string
    {
        preg_match(
            '/'.preg_quote($label, '/').'.*?fi-wi-stats-overview-stat-value">\s*(.*?)\s*</s',
            $html,
            $matches,
        );

        return trim($matches[1] ?? '');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
