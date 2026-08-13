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
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Enums\PaymentPurpose;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Gateways\VirtualPayGateway;
use Modules\Payments\Models\PaymentIntent;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Tests\TestCase;

/**
 * The wallet screen: balance up front, recharge via Pix below it. Covers
 * the balance readout, opening a Recharge PaymentIntent (never crediting the
 * wallet directly — only the webhook does that, see CreateRecharge), the
 * amount guardrails, and the ManageBilling gate on the recharge action.
 *
 * Mirrors settings/⚡billing.blade.php's Pix pattern and CreateRechargeTest's
 * gateway fake — no network in this suite either.
 */
class WalletTest extends TestCase
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

        Livewire::test('pages::checker.wallet')
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

        Livewire::test('pages::checker.wallet')
            ->set('amount', '50,00')
            ->call('recharge')
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

        Livewire::test('pages::checker.wallet')
            ->set('amount', '1')
            ->call('recharge')
            ->assertHasErrors(['amount']);

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
    }

    public function test_an_invalid_amount_does_not_open_a_pix_charge(): void
    {
        Http::fake();

        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.wallet')
            ->set('amount', 'abc')
            ->call('recharge')
            ->assertHasErrors(['amount']);

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);

        Livewire::test('pages::checker.wallet')
            ->set('amount', '')
            ->call('recharge')
            ->assertHasErrors(['amount']);

        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
    }

    public function test_recharging_requires_manage_billing_permission(): void
    {
        Http::fake();

        // Admin tem ViewBilling (vê a tela) mas não ManageBilling.
        [$user, $team] = $this->memberOf(TeamRole::Admin);
        Wallet::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.wallet')
            ->set('amount', '50,00')
            ->call('recharge')
            ->assertForbidden();

        Http::assertNothingSent();
        expect(PaymentIntent::acrossTenants()->count())->toBe(0);
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

        $response = $this->actingAs($user)->get(route('checker.wallet'));

        $response->assertForbidden();
    }

    public function test_the_ledger_shows_the_teams_entries(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);
        $wallet = Wallet::factory()->create(['team_id' => $team->id]);

        WalletTransaction::factory()->create([
            'team_id' => $team->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::Recharge,
            'amount_cents' => 5000,
            'balance_after_cents' => 5000,
            'description' => 'Recarga via Pix',
        ]);

        WalletTransaction::factory()->create([
            'team_id' => $team->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::Charge,
            'amount_cents' => -1200,
            'balance_after_cents' => 3800,
            'description' => 'Consulta CPF',
        ]);

        // Isolamento: um lançamento de outro time não deve aparecer — o
        // escopo do tenant é quem garante isso, não um filtro manual.
        $outroTime = Team::factory()->create();
        $outraCarteira = Wallet::factory()->create(['team_id' => $outroTime->id]);
        WalletTransaction::factory()->create([
            'team_id' => $outroTime->id,
            'wallet_id' => $outraCarteira->id,
            'description' => 'Lançamento de outro time',
        ]);

        $this->actingAs($user);

        Livewire::test('pages::checker.wallet')
            ->assertSee('Recarga via Pix')
            ->assertSee('Consulta CPF')
            ->assertSee((string) Number::currency(50, in: 'BRL', locale: 'pt_BR'))
            ->assertSee((string) Number::currency(12, in: 'BRL', locale: 'pt_BR'))
            ->assertSee((string) Number::currency(38, in: 'BRL', locale: 'pt_BR'))
            ->assertDontSee('Lançamento de outro time');
    }

    public function test_the_recharge_history_shows_the_teams_payment_intents(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->create(['team_id' => $team->id]);

        PaymentIntent::factory()->paid()->create([
            'team_id' => $team->id,
            'purpose' => PaymentPurpose::Recharge,
            'amount_cents' => 5000,
        ]);

        PaymentIntent::factory()->create([
            'team_id' => $team->id,
            'purpose' => PaymentPurpose::Recharge,
            'status' => PaymentStatus::Pending,
            'amount_cents' => 3000,
        ]);

        // Um intent de compra de plano não é uma recarga — não deve aparecer
        // nesta seção.
        PaymentIntent::factory()->paid()->create([
            'team_id' => $team->id,
            'purpose' => PaymentPurpose::Plan,
            'amount_cents' => 9900,
        ]);

        $this->actingAs($user);

        Livewire::test('pages::checker.wallet')
            ->assertSee((string) Number::currency(50, in: 'BRL', locale: 'pt_BR'))
            ->assertSee((string) Number::currency(30, in: 'BRL', locale: 'pt_BR'))
            ->assertDontSee((string) Number::currency(99, in: 'BRL', locale: 'pt_BR'));
    }

    public function test_the_recharge_history_excludes_other_teams_intents(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->create(['team_id' => $team->id]);

        PaymentIntent::factory()->create([
            'team_id' => $team->id,
            'purpose' => PaymentPurpose::Recharge,
            'amount_cents' => 7700,
        ]);

        // Isolamento: um intent de recarga de outro time não deve aparecer —
        // o escopo do tenant é quem garante isso, não um filtro manual.
        // Mesmo mecanismo de test_the_ledger_shows_the_teams_entries: time
        // real, team_id explícito na factory.
        $outroTime = Team::factory()->create();
        PaymentIntent::factory()->create([
            'team_id' => $outroTime->id,
            'purpose' => PaymentPurpose::Recharge,
            'amount_cents' => 8800,
        ]);

        $this->actingAs($user);

        Livewire::test('pages::checker.wallet')
            ->assertSee((string) Number::currency(77, in: 'BRL', locale: 'pt_BR'))
            ->assertDontSee((string) Number::currency(88, in: 'BRL', locale: 'pt_BR'));
    }

    public function test_empty_states_show_an_invitation(): void
    {
        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.wallet')
            ->assertSee('Nenhum lançamento ainda')
            ->assertSee('Nenhuma recarga ainda');
    }

    public function test_a_comma_decimal_amount_charges_the_correct_centavos(): void
    {
        Http::fake(['*/transactions' => Http::response([
            'id' => 'uuid-comma', 'copy_paste' => '000201-comma', 'qr_code' => 'base64comma==', 'status' => 'pending',
        ], 201)]);

        [$user, $team] = $this->memberOf(TeamRole::Owner);
        Wallet::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        Livewire::test('pages::checker.wallet')
            ->set('amount', '10,50')
            ->call('recharge')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payment_intents', [
            'amount_cents' => 1050,
            'purpose' => 'recharge',
        ]);
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
