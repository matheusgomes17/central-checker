<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Tests\TestCase;

/**
 * The "histórico de consultas" screen: a user's own past runs, with the raw
 * result opened only for its owner and the input always shown masked.
 *
 * The tenant scope is per-team, not per-user — a team can have several
 * members — so "team-scoped" alone would leak a teammate's runs into this
 * list. Both the listing and the view-result path add an explicit
 * `where('user_id', Auth::id())` on top of the tenant scope; this suite
 * exists to prove both halves, the same shape as the Fix 1 regression test in
 * ExecutarConsultaTest.
 */
class HistoricoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_history_lists_the_users_executions(): void
    {
        [$userA, $team] = $this->memberOf();
        $userB = $this->addMember($team);

        $checkA = Check::factory()->create(['name' => 'Consulta cadastral']);
        $checkB = Check::factory()->create(['name' => 'Consulta financeira']);

        CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $checkA->id,
            'user_id' => $userA->id,
            'outcome' => ProviderOutcome::Success,
            'input_masked' => ['cpf' => '123******01'],
        ]);

        // Same team, different user — must not show up in A's history.
        CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $checkB->id,
            'user_id' => $userB->id,
            'outcome' => ProviderOutcome::Success,
            'input_masked' => ['cpf' => '999******02'],
        ]);

        $this->actingAs($userA);

        Livewire::test('pages::checker.historico')
            ->assertOk()
            ->assertSee('Consulta cadastral')
            ->assertSee('123******01')
            ->assertDontSee('Consulta financeira')
            ->assertDontSee('999******02');
    }

    public function test_the_raw_document_is_never_shown(): void
    {
        [$user, $team] = $this->memberOf();
        $check = Check::factory()->create();

        CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'user_id' => $user->id,
            'outcome' => ProviderOutcome::Success,
            'input_masked' => ['cpf' => '123******01'],
            'result' => ['nome' => 'MARCADOR-SECRETO-98765'],
        ]);

        $this->actingAs($user);

        Livewire::test('pages::checker.historico')
            ->assertOk()
            ->assertSee('123******01')
            ->assertDontSee('MARCADOR-SECRETO-98765');
    }

    public function test_viewing_a_result_shows_it_only_to_the_owner(): void
    {
        [$userA, $team] = $this->memberOf();
        $userB = $this->addMember($team);

        $check = Check::factory()->create();

        $execution = CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'user_id' => $userA->id,
            'outcome' => ProviderOutcome::Success,
            'result' => ['nome' => 'Fulano de Tal'],
        ]);

        $this->actingAs($userA);

        Livewire::test('pages::checker.historico')
            ->call('ver', $execution->id)
            ->assertSee('Fulano de Tal');

        $this->actingAs($userB);

        Livewire::test('pages::checker.historico')
            ->call('ver', $execution->id)
            ->assertDontSee('Fulano de Tal');
    }

    public function test_no_data_and_failed_have_no_view_button(): void
    {
        [$user, $team] = $this->memberOf();
        $check = Check::factory()->create();

        $noData = CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'user_id' => $user->id,
            'outcome' => ProviderOutcome::NoData,
            'result' => null,
        ]);

        $failed = CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'user_id' => $user->id,
            'outcome' => ProviderOutcome::Failed,
            'result' => null,
            'failure_reason' => 'timeout',
        ]);

        $this->actingAs($user);

        Livewire::test('pages::checker.historico')
            ->assertOk()
            ->assertDontSeeHtml('wire:click="ver('.$noData->id.')"')
            ->assertDontSeeHtml('wire:click="ver('.$failed->id.')"');
    }

    public function test_empty_state_invites_the_user(): void
    {
        [$user] = $this->memberOf();

        $this->actingAs($user);

        Livewire::test('pages::checker.historico')
            ->assertOk()
            ->assertSee('você ainda não rodou');
    }

    public function test_the_page_requires_team_membership(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($user)->get(route('checker.historico', $team));

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

    private function addMember(Team $team): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Member);
        $user->switchTeam($team);

        return $user;
    }
}
