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
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Enums\PaymentPurpose;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Gateways\VirtualPayGateway;
use Modules\Payments\Models\PaymentIntent;
use Tests\TestCase;

/**
 * The plans screen: current plan + status up front, the purchasable plans
 * with their included consultas (allowance) below, subscribe via Pix.
 *
 * A second entry point to the same PurchasePlan action as
 * settings/⚡billing.blade.php — mirrors its subscribe()/downgrade-confirm
 * dance and WalletTest's gateway fake, no network in this suite either.
 */
class PlansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mesmo bind de WalletTest/CreateRechargeTest: instância concreta
        // de VirtualPayGateway, com a chamada HTTP interceptada por
        // Http::fake() em cada teste que precisa dela.
        app()->bind(PaymentGateway::class, fn (): VirtualPayGateway => new VirtualPayGateway('https://api.virtualpay.online/v2', 'cid', 'csecret'));
    }

    public function test_the_page_shows_the_current_plan_and_status(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);

        $this->actingAs($user);

        Livewire::test('pages::checker.plans')
            ->assertOk()
            ->assertSee('Free')
            ->assertSee('Sem assinatura');
    }

    public function test_the_page_lists_purchasable_plans_with_included_checks(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);

        $checkWithAllowance = Check::factory()->create(['name' => 'Consulta CPF']);
        PlanCheck::factory()->create([
            'plan' => 'pro',
            'check_id' => $checkWithAllowance->id,
            'quota' => 100,
        ]);

        $unlimitedCheck = Check::factory()->create(['name' => 'Consulta CNPJ']);
        PlanCheck::factory()->unlimited()->create([
            'plan' => 'business',
            'check_id' => $unlimitedCheck->id,
        ]);

        $this->actingAs($user);

        Livewire::test('pages::checker.plans')
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

        Livewire::test('pages::checker.plans')
            ->call('subscribe', 'pro')
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

        Livewire::test('pages::checker.plans')
            ->call('subscribe', 'pro')
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

        $component = Livewire::test('pages::checker.plans')
            ->call('subscribe', 'pro')
            ->assertHasNoErrors()
            ->assertSet('confirming', 'pro')
            ->assertSet('intentId', null);

        expect(PaymentIntent::acrossTenants()->count())->toBe(0);

        $component->call('subscribe', 'pro')
            ->assertHasNoErrors()
            ->assertSet('confirming', null);

        expect(PaymentIntent::acrossTenants()->count())->toBe(1);
    }

    public function test_an_invalid_plan_key_is_rejected(): void
    {
        Http::fake();

        [$user, $team] = $this->memberOf(TeamRole::Owner);

        $this->actingAs($user);

        Livewire::test('pages::checker.plans')
            ->call('subscribe', 'inexistente')
            ->assertNotFound();

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
    }

    /**
     * `confirming` is a plain public property (not #[Locked], because the
     * "Cancelar" button needs `$set('confirming', null)` from the client),
     * so a tampered Livewire request can set it to a key that isn't in
     * Plan::purchasable(). The view must not crash indexing that away —
     * subscribe() already re-validates, but the render path has to guard too.
     */
    public function test_setting_an_invalid_confirming_key_does_not_crash_the_page(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);

        $this->actingAs($user);

        Livewire::test('pages::checker.plans')
            ->set('confirming', 'inexistente')
            ->assertOk()
            ->assertSee('Free')
            ->assertDontSee('Este plano é menor que o uso atual');
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

        $response = $this->actingAs($user)->get(route('checker.plans'));

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
