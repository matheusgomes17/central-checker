<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Modules\Carteira\Models\Wallet;
use Modules\Pagamentos\Contracts\PaymentGateway;
use Modules\Pagamentos\Enums\PaymentPurpose;
use Modules\Pagamentos\Enums\PaymentStatus;
use Modules\Pagamentos\Gateways\VirtualPayGateway;
use Modules\Pagamentos\Models\PaymentIntent;
use Tests\TestCase;

/**
 * The carteira screen: balance up front, recharge via Pix below it. Covers
 * the balance readout, opening a Recharge PaymentIntent (never crediting the
 * wallet directly — only the webhook does that, see CreateRecharge), the
 * amount guardrails, and the ManageBilling gate on the recharge action.
 *
 * Mirrors settings/⚡billing.blade.php's Pix pattern and CreateRechargeTest's
 * gateway fake — no network in this suite either.
 */
class CarteiraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mesmo bind de CreateRechargeTest: uma instância concreta de
        // VirtualPayGateway para a action resolver, com a chamada HTTP real
        // interceptada por Http::fake() em cada teste que precisa dela.
        app()->bind(PaymentGateway::class, fn (): VirtualPayGateway => new VirtualPayGateway('https://api.virtualpay.online/v2', 'cid', 'csecret'));

        Config::set('billing.recharge.min_cents', 1000);
        Config::set('billing.recharge.max_cents', 100000);
    }

    public function test_the_page_loads_and_shows_the_balance(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->withBalance(543210)->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.carteira')
            ->assertOk()
            ->assertSee((string) Number::currency(5432.10, in: 'BRL', locale: 'pt_BR'));
    }

    public function test_generating_a_pix_charge_opens_a_recharge_intent_and_shows_qr_and_copy_paste(): void
    {
        Http::fake(['*/transactions' => Http::response([
            'id' => 'uuid-1', 'copy_paste' => '000201-copia-e-cola', 'qr_code' => 'base64qr==', 'status' => 'pending',
        ], 201)]);

        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.carteira')
            ->set('valor', '50,00')
            ->call('recarregar')
            ->assertHasNoErrors()
            ->assertSee('000201-copia-e-cola')
            ->assertSee('base64qr==', false);

        $intent = PaymentIntent::query()->sole();

        expect($intent->purpose)->toBe(PaymentPurpose::Recharge)
            ->and($intent->amount_cents)->toBe(5000)
            ->and($intent->status)->toBe(PaymentStatus::Pending);

        // A carteira não é creditada aqui — só o webhook credita quando o
        // pagamento é confirmado.
        expect(Wallet::forTeam($team)->balance_cents)->toBe(0);
    }

    public function test_an_amount_out_of_range_shows_a_friendly_message(): void
    {
        Http::fake();

        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.carteira')
            ->set('valor', '1')
            ->call('recarregar')
            ->assertHasErrors(['valor']);

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
    }

    public function test_an_invalid_amount_does_not_open_a_pix_charge(): void
    {
        Http::fake();

        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.carteira')
            ->set('valor', 'abc')
            ->call('recarregar')
            ->assertHasErrors(['valor']);

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);

        Livewire::test('pages::checker.carteira')
            ->set('valor', '')
            ->call('recarregar')
            ->assertHasErrors(['valor']);

        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
    }

    public function test_recharging_requires_manage_billing_permission(): void
    {
        Http::fake();

        // Admin tem ViewBilling (vê a tela) mas não ManageBilling.
        [$user, $team] = $this->memberOf(TeamRole::Admin);
        Wallet::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.carteira')
            ->set('valor', '50,00')
            ->call('recarregar')
            ->assertForbidden();

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
    }

    public function test_the_page_requires_team_membership(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($user)->get(route('checker.carteira', $team));

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
