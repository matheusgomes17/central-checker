<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Actions\CheckerApi\ExecuteCheck;
use App\Enums\TeamRole;
use App\Models\PlanCheck;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Models\User;
use App\Models\UserCheckOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ProviderResult;
use Modules\Wallet\Actions\CreditWallet;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The "execute a check" screen: pick a check, paste a batch of documents
 * (one per line), run every one of them, see a per-line result plus a
 * summary. Covers the input contract (newline-split, trimmed, duplicates
 * removed, capped), the exception-to-status mapping for every way
 * {@see ExecuteCheck::handle()} can refuse — caught per line so one bad
 * document never aborts the batch — and that checks in maintenance stay
 * visible instead of disappearing (D8).
 */
class ExecuteCheckScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_loads_and_lists_available_checks(): void
    {
        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::noData()));
        $check = Check::factory()->create(['provider_key' => 'fake', 'name' => 'Consulta cadastral']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->assertOk()
            ->assertSee($check->name);
    }

    public function test_a_batch_of_valid_documents_runs_and_charges_each_one(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(CreditWallet::class)->handle($team, 10_000, WalletTransactionType::Recharge, 'Fundo');
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(
            ProviderResult::success(['nome' => 'Fulano de Tal']),
            ProviderResult::noData(),
            ProviderResult::success(['nome' => 'Beltrano']),
        ));
        $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "11111111111\n22222222222\n33333333333")
            ->call('execute')
            ->assertHasNoErrors()
            ->assertSee('Fulano de Tal')
            ->assertSee('Beltrano');

        expect(CheckExecution::query()->count())->toBe(3)
            ->and(CheckExecution::query()->where('outcome', ProviderOutcome::Success)->count())->toBe(2)
            ->and(CheckExecution::query()->where('outcome', ProviderOutcome::NoData)->count())->toBe(1)
            // Duas execuções de sucesso cobradas a 350, a sem-registro não cobra nada.
            ->and(Wallet::forTeam($team)->balance_cents)->toBe(10_000 - 350 * 2);
    }

    public function test_blank_lines_are_ignored_and_duplicates_run_once(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(CreditWallet::class)->handle($team, 10_000, WalletTransactionType::Recharge, 'Fundo');
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "11111111111\n\n11111111111\n22222222222")
            ->call('execute')
            ->assertHasNoErrors();

        expect(CheckExecution::query()->count())->toBe(2);
    }

    public function test_an_invalid_line_is_marked_and_not_executed_while_valid_lines_still_run(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(CreditWallet::class)->handle($team, 10_000, WalletTransactionType::Recharge, 'Fundo');
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "11111111111\nabc\n22222222222")
            ->call('execute')
            ->assertHasNoErrors()
            ->assertSee('Inválido');

        expect(CheckExecution::query()->count())->toBe(2);
    }

    /**
     * {@see CheckInput::masked()} intentionally leaves short values (under 6
     * chars) untouched — safe for its normal use (an already-validated
     * 11/14-digit document), but not for an `invalid` line, whose raw text
     * never passed that length check. A short invalid line ("1234", a single
     * digit) must go through a mask that always obscures, or the raw value
     * — third-party PII — renders verbatim. Written to fail first against a
     * version of execute() that masks invalid lines with `mask()` instead of
     * `hardMask()`.
     */
    public function test_a_short_invalid_line_never_leaks_the_raw_value(): void
    {
        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "1234\n5")
            ->call('execute')
            ->assertHasNoErrors()
            ->assertSee('Inválido')
            ->assertDontSee('1234');

        expect(CheckExecution::query()->count())->toBe(0);
    }

    /**
     * The wallet covers exactly the first paid line. The second cannot be
     * reserved, so {@see ExecuteCheck::handle()} throws
     * InsufficientBalanceException — caught per line, not left to bubble up
     * and 500 the whole batch. Written to fail first against a version of
     * execute() missing the per-line catch: without it, the second line's
     * exception propagates and the request errors instead of finishing with
     * a friendly "insufficient" row.
     */
    public function test_insufficient_balance_partway_marks_the_rest_without_aborting_the_batch(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(CreditWallet::class)->handle($team, 500, WalletTransactionType::Recharge, 'Fundo');
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->priced(500)->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "11111111111\n22222222222")
            ->call('execute')
            ->assertHasNoErrors()
            ->assertSee('Saldo insuficiente');

        expect(CheckExecution::query()->count())->toBe(1)
            ->and(Wallet::forTeam($team)->balance_cents)->toBe(0);
    }

    /**
     * A plan allowance with quota 1 and a free check (no price to fall back
     * on): the first line consumes the whole quota, the second hits
     * {@see \Modules\CheckerApi\Exceptions\AllowanceExhaustedException} —
     * caught per line, marked 'allowance_exhausted', not recorded, batch
     * still finishes.
     */
    public function test_allowance_exhausted_partway_marks_the_rest_without_aborting_the_batch(): void
    {
        [$user, $team] = $this->memberOf();
        PlanSubscription::factory()->active()->create(['team_id' => $team->id, 'plan' => 'pro']);
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);
        PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 1]);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "11111111111\n22222222222")
            ->call('execute')
            ->assertHasNoErrors()
            ->assertSee('Franquia esgotada');

        expect(CheckExecution::query()->count())->toBe(1);
    }

    /**
     * A UserCheckOverride blocks this user for this check, regardless of
     * plan or wallet — every line hits
     * {@see \Modules\CheckerApi\Exceptions\CheckBlockedForUserException}
     * before a provider is ever called, marked 'blocked', batch still
     * finishes instead of failing on the first line.
     */
    public function test_a_blocked_user_marks_every_line_without_aborting_the_batch(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);
        UserCheckOverride::factory()->blocked()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'check_id' => $check->id,
        ]);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "11111111111\n22222222222")
            ->call('execute')
            ->assertHasNoErrors()
            ->assertSee('Bloqueado');

        expect(CheckExecution::query()->count())->toBe(0);
    }

    /**
     * The top-of-execute() availability guard only catches a check that was
     * already down before the batch started. This drives the circuit
     * breaker open mid-batch instead: three lines return a Failed outcome
     * (two provider attempts each, per {@see \Modules\CheckerApi\Support\ResilientProvider::MAX_TRIES}),
     * which trips {@see \Modules\CheckerApi\Support\CircuitBreaker::FAILURE_THRESHOLD}
     * (5) partway through the third. The fourth line's own
     * {@see ExecuteCheck::handle()} call re-checks availability at entry and
     * throws {@see \Modules\CheckerApi\Exceptions\CheckUnavailableException}
     * — caught per line, marked 'unavailable', batch still finishes.
     */
    public function test_check_unavailable_mid_batch_marks_the_rest_without_aborting_the_batch(): void
    {
        Sleep::fake();

        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::failed('erro do provedor')));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "11111111111\n22222222222\n33333333333\n44444444444")
            ->call('execute')
            ->assertHasNoErrors()
            ->assertSee('Em manutenção');

        expect(CheckExecution::query()->where('outcome', ProviderOutcome::Failed)->count())->toBe(3)
            ->and(CheckExecution::query()->count())->toBe(3);
    }

    public function test_more_than_the_cap_is_refused_without_running_anything(): void
    {
        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $lines = collect(range(1, 101))
            ->map(fn (int $i): string => str_pad((string) $i, 11, '0', STR_PAD_LEFT))
            ->implode("\n");

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', $lines)
            ->call('execute')
            ->assertHasErrors(['documents']);

        expect(CheckExecution::query()->count())->toBe(0);
    }

    public function test_empty_or_blank_lines_only_input_is_refused(): void
    {
        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', "   \n\n   ")
            ->call('execute')
            ->assertHasErrors(['documents']);

        expect(CheckExecution::query()->count())->toBe(0);
    }

    public function test_the_raw_document_is_never_shown_only_the_masked_form(): void
    {
        [$user, $team] = $this->memberOf();
        resolve(CreditWallet::class)->handle($team, 1000, WalletTransactionType::Recharge, 'Fundo');
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['segredo' => 'TOPSECRET123'])));
        $check = Check::factory()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', '12345678909')
            ->call('execute')
            ->assertHasNoErrors()
            // O resultado (dado de terceiro devolvido pela consulta) aparece normalmente...
            ->assertSee('TOPSECRET123')
            // ...mas o documento bruto nunca, só a forma mascarada.
            ->assertSee('123******09')
            ->assertDontSee('12345678909');
    }

    /**
     * Cross-user isolation, same team. `results` is #[Locked], so a crafted
     * request cannot inject entries at all — Livewire refuses the update
     * outright. If that guard were ever removed, `rows()` still re-scopes
     * every execution_id by `user_id` on read, so a same-team sibling's
     * (enumerable, auto-increment) execution id would resolve to no result.
     */
    public function test_a_team_member_cannot_view_a_teammates_batch_via_a_crafted_results_array(): void
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

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', '12345678909')
            ->call('execute')
            ->assertHasNoErrors();

        $bExecution = CheckExecution::query()->sole();

        $memberA = User::factory()->create();
        $team->members()->attach($memberA);
        $memberA->assignTeamRole($team, TeamRole::Member);
        $memberA->switchTeam($team);

        $this->actingAs($memberA);

        try {
            $component = Livewire::test('pages::checker.execute')
                ->set('results', [[
                    'masked' => '123******09',
                    'status' => 'success',
                    'execution_id' => $bExecution->id,
                ]]);
        } catch (CannotUpdateLockedPropertyException $e) {
            expect($e->property)->toBe('results');

            return;
        }

        $component->assertDontSee('Fulano de Tal');
    }

    /**
     * The test above only exercises the #[Locked] guard — Livewire refuses
     * the crafted `->set('results', ...)` before `rows()` ever runs, so the
     * `where('user_id', Auth::id())` inside it never executes on that path.
     * This one bypasses #[Locked] directly (via reflection, standing in for
     * "the trap door disappears somehow") to prove the second layer holds on
     * its own: `rows()` must still refuse to resolve a same-team sibling's
     * execution id to their private, encrypted-at-rest result. Written to
     * fail first against a version of `rows()` without the
     * `where('user_id', ...)` scope — with that removed,
     * `$rows->first()['result']` comes back with the teammate's data instead
     * of null.
     */
    public function test_rows_scopes_execution_lookup_to_the_owner_even_if_locked_is_bypassed(): void
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

        Livewire::test('pages::checker.execute')
            ->set('checkId', $check->id)
            ->set('documents', '12345678909')
            ->call('execute')
            ->assertHasNoErrors();

        $bExecution = CheckExecution::query()->sole();

        $memberA = User::factory()->create();
        $team->members()->attach($memberA);
        $memberA->assignTeamRole($team, TeamRole::Member);
        $memberA->switchTeam($team);

        $this->actingAs($memberA);

        $component = Livewire::test('pages::checker.execute')->instance();

        // Escreve a propriedade direto via reflection, contornando o
        // #[Locked] — simula o que aconteceria se a trava do Livewire
        // sumisse, para provar que o where('user_id') em rows() segura
        // sozinho.
        new ReflectionProperty($component, 'results')->setValue($component, [[
            'masked' => '123******09',
            'status' => 'success',
            'execution_id' => $bExecution->id,
        ]]);

        // rows() also only exists on the Volt anonymous class, invisible to
        // static analysis the same way `results` above is — call it through
        // reflection too, and narrow the otherwise-mixed return with a @var
        // matching the component's own documented shape for rows().
        /** @var \Illuminate\Support\Collection<int, array{masked: string, status: string, result: array<string, mixed>|null, cost: string|null}> $rows */
        $rows = new ReflectionMethod($component, 'rows')->invoke($component);

        $row = $rows->sole();

        expect($row['result'])->toBeNull()
            ->and($row['cost'])->toBeNull();
    }

    public function test_a_check_in_maintenance_is_refused_before_running_anything(): void
    {
        [$user] = $this->memberOf();
        resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
        $check = Check::factory()->inMaintenance()->create(['provider_key' => 'fake']);

        $this->actingAs($user);

        $component = Livewire::test('pages::checker.execute')
            ->assertSee($check->name)
            ->assertSee('manutenção');

        $component->set('checkId', $check->id)
            ->set('documents', '11111111111')
            ->call('execute')
            ->assertHasErrors(['checkId']);

        expect(CheckExecution::query()->count())->toBe(0);
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

        $response = $this->actingAs($user)->get(route('checker.execute'));

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
