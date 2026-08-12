<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\TeamRole;
use App\Models\PlanCheck;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\CheckerApi\Models\Check;
use Modules\Pagamentos\Contracts\PaymentGateway;
use Modules\Pagamentos\Enums\PaymentPurpose;
use Modules\Pagamentos\Enums\PaymentStatus;
use Modules\Pagamentos\Gateways\VirtualPayGateway;
use Modules\Pagamentos\Models\PaymentIntent;
use Tests\TestCase;

/**
 * The planos screen: current plan + status up front, the purchasable plans
 * with their included consultas (franquia) below, subscribe via Pix.
 *
 * A second entry point to the same PurchasePlan action as
 * settings/⚡billing.blade.php — mirrors its subscribe()/downgrade-confirm
 * dance and CarteiraTest's gateway fake, no network in this suite either.
 */
class PlanosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mesmo bind de CarteiraTest/CreateRechargeTest: instância concreta
        // de VirtualPayGateway, com a chamada HTTP interceptada por
        // Http::fake() em cada teste que precisa dela.
        app()->bind(PaymentGateway::class, fn (): VirtualPayGateway => new VirtualPayGateway('https://api.virtualpay.online/v2', 'cid', 'csecret'));
    }

    public function test_the_page_shows_the_current_plan_and_status(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);

        $this->actingAs($user);

        Livewire::test('pages::checker.planos')
            ->assertOk()
            ->assertSee('Free')
            ->assertSee('No subscription');
    }

    public function test_the_page_lists_purchasable_plans_with_included_checks(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);

        $checkComFranquia = Check::factory()->create(['name' => 'Consulta CPF']);
        PlanCheck::factory()->create([
            'plan' => 'pro',
            'check_id' => $checkComFranquia->id,
            'quota' => 100,
        ]);

        $checkIlimitado = Check::factory()->create(['name' => 'Consulta CNPJ']);
        PlanCheck::factory()->unlimited()->create([
            'plan' => 'business',
            'check_id' => $checkIlimitado->id,
        ]);

        $this->actingAs($user);

        Livewire::test('pages::checker.planos')
            ->assertOk()
            ->assertSee('Pro')
            ->assertSee('Business')
            ->assertSee('Consulta CPF')
            ->assertSee('100/mês')
            ->assertSee('Consulta CNPJ')
            ->assertSee('ilimitado');
    }

    public function test_subscribing_opens_a_plan_payment_intent_and_shows_pix(): void
    {
        Http::fake(['*/transactions' => Http::response([
            'id' => 'uuid-plano', 'copy_paste' => '000201-plano-copia-e-cola', 'qr_code' => 'base64planoqr==', 'status' => 'pending',
        ], 201)]);

        [$user, $team] = $this->memberOf(TeamRole::Owner);

        $this->actingAs($user);

        Livewire::test('pages::checker.planos')
            ->call('assinar', 'pro')
            ->assertHasNoErrors()
            ->assertSee('000201-plano-copia-e-cola');

        $intent = PaymentIntent::query()->sole();

        expect($intent->purpose)->toBe(PaymentPurpose::Plan)
            ->and($intent->plan)->toBe('pro')
            ->and($intent->amount_cents)->toBe(4900)
            ->and($intent->status)->toBe(PaymentStatus::Pending);

        // Assinar só abre o Pix — a assinatura só é ativada pelo webhook.
        expect(PlanSubscription::query()->where('team_id', $team->id)->count())->toBe(0);
    }

    public function test_subscribing_requires_manage_billing(): void
    {
        Http::fake();

        // Admin tem ViewBilling (vê a tela) mas não ManageBilling.
        [$user, $team] = $this->memberOf(TeamRole::Admin);

        $this->actingAs($user);

        Livewire::test('pages::checker.planos')
            ->call('assinar', 'pro')
            ->assertForbidden();

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
    }

    public function test_a_downgrade_asks_for_confirmation_first(): void
    {
        Http::fake(['*/transactions' => Http::response([
            'id' => 'uuid-confirmado', 'copy_paste' => '000201-confirmado', 'qr_code' => 'base64confirmado==', 'status' => 'pending',
        ], 201)]);

        [$user, $team] = $this->memberOf(TeamRole::Owner);

        // Pro tem teto de 20 membros — 21 memberships deixa o time acima do
        // teto do plano-alvo, disparando a confirmação de downgrade.
        $extras = User::factory()->count(20)->create();
        $team->members()->attach($extras->pluck('id'));

        $this->actingAs($user);

        $component = Livewire::test('pages::checker.planos')
            ->call('assinar', 'pro')
            ->assertHasNoErrors()
            ->assertSet('confirming', 'pro')
            ->assertSet('intentId', null);

        expect(PaymentIntent::acrossTenants()->count())->toBe(0);

        $component->call('assinar', 'pro')
            ->assertHasNoErrors()
            ->assertSet('confirming', null);

        expect(PaymentIntent::acrossTenants()->count())->toBe(1);
    }

    public function test_an_invalid_plan_key_is_rejected(): void
    {
        Http::fake();

        [$user, $team] = $this->memberOf(TeamRole::Owner);

        $this->actingAs($user);

        Livewire::test('pages::checker.planos')
            ->call('assinar', 'inexistente')
            ->assertNotFound();

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
    }

    public function test_the_page_requires_team_membership(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($user)->get(route('checker.planos', $team));

        $response->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Team}
     */
    private function memberOf(TeamRole $role): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, $role);
        $user->switchTeam($team);

        return [$user, $team];
    }
}
