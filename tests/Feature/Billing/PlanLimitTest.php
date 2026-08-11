<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Teams\InviteTeamMember;
use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Exceptions\PlanLimitException;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Billing\Plan;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ceiling the free plan advertises, finally applied.
 *
 * It is checked in the action and not in middleware because inviting from the
 * interface travels through a route owned by Livewire: route middleware never
 * sees it. The three doors — dialog, import and API — already meet in the
 * action.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_action_refuses_the_invitation_that_would_cross_the_ceiling(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        $this->expectException(PlanLimitException::class);

        resolve(InviteTeamMember::class)->handle($team, 'nova@example.test', TeamRole::Member, $owner);
    }

    public function test_the_refused_invitation_is_not_created(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        try {
            resolve(InviteTeamMember::class)->handle($team, 'nova@example.test', TeamRole::Member, $owner);
        } catch (PlanLimitException) {
            // O que importa é o efeito colateral, não a exceção — ela já tem
            // teste próprio acima.
        }

        $this->assertSame(0, TeamInvitation::query()->where('email', 'nova@example.test')->count());
    }

    /**
     * The message carries the numbers. "Upgrade to continue" with no plan name
     * and no ceiling is the kind of wall people open support tickets about.
     */
    public function test_the_message_names_the_plan_and_the_ceiling(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        try {
            resolve(InviteTeamMember::class)->handle($team, 'nova@example.test', TeamRole::Member, $owner);
            $this->fail('A action deveria ter recusado o convite.');
        } catch (PlanLimitException $e) {
            $this->assertStringContainsString('Free', $e->getMessage());
            $this->assertStringContainsString('3', $e->getMessage());
        }
    }

    public function test_a_team_below_the_ceiling_still_invites(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();
        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $invitation = resolve(InviteTeamMember::class)->handle($team, 'cabe@example.test', TeamRole::Member, $owner);

        $this->assertSame('cabe@example.test', $invitation->email);
    }

    /**
     * Over the API the refusal must not read as our fault. PlanLimitException
     * extends RuntimeException, and without a branch of its own ApiProblem
     * answers 500 server_error for a ceiling working exactly as designed.
     */
    public function test_the_api_answers_payment_required_and_not_a_server_error(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        // O time precisa alcançar o recurso `api` para chegar à checagem de
        // teto que este teste investiga — assinar o Pro resolveria o acesso,
        // mas também levantaria o teto de membros que o time está testando
        // aqui de propósito, então o caminho é a mesma liberação pontual que
        // o suporte usaria numa conta real.
        Feature::for($team)->activate('api');

        $token = $owner->createToken('teste', ApiAbility::Write->implies());
        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $team->id]);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/invitations', ['email' => 'nova@example.test', 'role' => TeamRole::Member->value])
            ->assertStatus(402)
            ->assertJsonPath('title', 'plan_limit_reached');
    }

    /**
     * The web surface must not read as broken either. A ceiling working as
     * designed showing up as a blank 500 page would tell whoever hit send
     * that the product crashed, not that the plan said no.
     */
    public function test_the_dialog_shows_the_ceiling_instead_of_crashing(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        $this->actingAs($owner);

        // A mesma mensagem que a action lança: o time está no plano padrão, e
        // é essa mensagem exata que o campo de e-mail deve exibir.
        $expectedMessage = PlanLimitException::reached('members', Plan::default(), 3)->getMessage();

        Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
            ->set('inviteEmail', 'nova@example.test')
            ->set('inviteRole', TeamRole::Member->value)
            ->call('createInvitation')
            ->assertHasErrors(['inviteEmail' => $expectedMessage]);

        $this->assertSame(0, TeamInvitation::query()->where('email', 'nova@example.test')->count());
    }

    /**
     * @return array{Team, User}
     */
    private function teamAtItsCeiling(): array
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        // O plano gratuito comporta três assentos; dois membros a mais fecham
        // a conta sem precisar de convite pendente no meio.
        foreach (range(1, 2) as $ignored) {
            $team->members()->attach(User::factory()->create());
        }

        return [$team, $owner];
    }
}
