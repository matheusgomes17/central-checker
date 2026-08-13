<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Actions\CheckerApi\ExecutarCheck;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ProviderResult;
use Tests\TestCase;

/**
 * The "executar consulta" screen: pick a check, type a single document
 * field, run it, see the result and what it cost. Covers the input contract
 * (one field, digits + length only, no check-digit yet — see the closure
 * rule in the volt component for why), the exception-to-message mapping for
 * every way {@see ExecutarCheck::handle()} can refuse, and that checks in
 * maintenance stay visible instead of disappearing (D8).
 */
class ExecutarConsultaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_loads_and_lists_available_checks(): void
    {
        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::noData()));
        $check = Check::factory()->create(['provider_key' => 'fake', 'name' => 'Consulta cadastral']);

        $this->actingAs($user);

        Livewire::test('pages::checker.executar')
            ->assertOk()
            ->assertSee($check->name);
    }

    public function test_a_successful_run_records_and_shows_the_result_and_cost(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(CreditWallet::class)->handle($team, 1000, WalletTransactionType::Recharge, 'Fundo');
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano de Tal'])));
        $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.executar')
            ->set('checkId', $check->id)
            ->set('documento', '12345678909')
            ->call('executar')
            ->assertHasNoErrors()
            ->assertSee('Fulano de Tal');

        $execution = CheckExecution::query()->sole();

        expect($execution->outcome)->toBe(ProviderOutcome::Success)
            ->and($execution->result)->toBe(['nome' => 'Fulano de Tal'])
            ->and(Wallet::forTeam($team)->balance_cents)->toBe(650);
    }

    public function test_an_invalid_document_does_not_execute(): void
    {
        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.executar')
            ->set('checkId', $check->id)
            ->set('documento', 'abc123')
            ->call('executar')
            ->assertHasErrors(['documento']);

        expect(CheckExecution::query()->count())->toBe(0);
    }

    public function test_no_data_does_not_charge_and_shows_a_message(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(CreditWallet::class)->handle($team, 1000, WalletTransactionType::Recharge, 'Fundo');
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::noData()));
        $check = Check::factory()->priced(500)->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.executar')
            ->set('checkId', $check->id)
            ->set('documento', '12345678909')
            ->call('executar')
            ->assertHasNoErrors()
            ->assertSee('nenhum registro');

        $execution = CheckExecution::query()->sole();

        expect($execution->outcome)->toBe(ProviderOutcome::NoData)
            ->and(Wallet::forTeam($team)->balance_cents)->toBe(1000);
    }

    /**
     * No plan covers this check (no franquia, no PlanSubscription at all) and
     * the wallet was never funded, so ReserveCredit refuses with
     * InsufficientBalanceException. Written to fail first against a missing
     * catch — without it, this 500s instead of showing a friendly message.
     */
    public function test_insufficient_balance_shows_a_friendly_message(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->priced(500)->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.executar')
            ->set('checkId', $check->id)
            ->set('documento', '12345678909')
            ->call('executar')
            ->assertHasErrors(['checkId'])
            ->assertSee('Saldo insuficiente');

        expect(CheckExecution::query()->count())->toBe(0)
            ->and(Wallet::forTeam($team)->balance_cents)->toBe(0);
    }

    public function test_a_check_in_maintenance_appears_disabled(): void
    {
        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->inMaintenance()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        $component = Livewire::test('pages::checker.executar')
            ->assertSee($check->name)
            ->assertSee('manutenção');

        $component->set('checkId', $check->id)
            ->set('documento', '12345678909')
            ->call('executar')
            ->assertHasErrors(['checkId']);

        expect(CheckExecution::query()->count())->toBe(0);
    }

    /**
     * Cross-user isolation, same team. Before Fix 1, `executionId` had no
     * #[Locked] and its `execution()` computed only scoped by team, so a
     * same-team sibling could set the property — an enumerable
     * auto-increment id — to a teammate's execution and read their raw
     * result (third-party PII) plus cost. Proven whichever
     * mechanism still applies: Livewire refuses to update the locked
     * property at all (the branch this test hits, since both parts of the
     * fix are in place), or — if that guard alone were ever removed — the
     * `user_id` scope in execution() keeps the computed from resolving to
     * a teammate's execution.
     */
    public function test_a_team_member_cannot_view_a_teammates_execution_via_a_crafted_execution_id(): void
    {
        $team = Team::factory()->create();

        $memberB = User::factory()->create();
        $team->members()->attach($memberB);
        $memberB->assignTeamRole($team, TeamRole::Member);
        $memberB->switchTeam($team);

        resolve(CreditWallet::class)->handle($team, 1000, WalletTransactionType::Recharge, 'Fundo');
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano de Tal'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $this->actingAs($memberB);

        Livewire::test('pages::checker.executar')
            ->set('checkId', $check->id)
            ->set('documento', '12345678909')
            ->call('executar')
            ->assertHasNoErrors();

        $bExecution = CheckExecution::query()->sole();

        $memberA = User::factory()->create();
        $team->members()->attach($memberA);
        $memberA->assignTeamRole($team, TeamRole::Member);
        $memberA->switchTeam($team);

        $this->actingAs($memberA);

        try {
            $component = Livewire::test('pages::checker.executar')
                ->set('executionId', $bExecution->id);
        } catch (CannotUpdateLockedPropertyException $e) {
            expect($e->property)->toBe('executionId');

            return;
        }

        $component->assertDontSee('Fulano de Tal')
            ->assertDontSee('resultado-execucao');
    }

    /**
     * There is no team slug in the URL anymore for the middleware to refuse,
     * so the only way left to reach the page without a team is to have none
     * as current — the edge case EnsureTeamMembership still 403s on.
     */
    public function test_the_page_requires_team_membership(): void
    {
        $user = User::factory()->create();
        // A relação currentTeam já foi carregada pelo switchTeam() da factory
        // — sem descartá-la, update() muda a coluna mas o objeto em memória
        // ainda responde com o time antigo.
        $user->update(['current_team_id' => null]);
        $user->unsetRelation('currentTeam');

        $response = $this->actingAs($user)->get(route('checker.executar'));

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
