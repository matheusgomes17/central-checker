<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Billing\SwapPlan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The one part of billing that fixtures cannot answer: whether Stripe accepts
 * what we send it.
 *
 * Opt-in on purpose. A suite that quietly calls a payment provider is a suite
 * that fails when the network does, takes seconds per test, and leaves objects
 * in someone's account — so this runs only when asked:
 *
 *     STRIPE_INTEGRATION=true php artisan test --filter=StripeIntegration
 *
 * It needs STRIPE_SECRET and a real price id in STRIPE_PRICE_PRO. Everything
 * it creates in Stripe is a test-mode customer, and it deletes them on the way
 * out.
 */
class StripeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The value `phpunit.xml` sets STRIPE_SECRET to, so the offline suite
     * never reaches Stripe by accident. `<env>` there only applies when the
     * shell has not already exported the variable, so a real key passed on
     * the command line still wins — this constant is what tells the two
     * apart.
     */
    private const string SENTINEL_SECRET = 'sk_test_deliberately_invalid';

    /**
     * @var array<int, string>
     */
    private array $customersCreated = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Config::boolean('billing.stripe_integration')) {
            $this->markTestSkipped('Defina STRIPE_INTEGRATION=true para rodar contra a Stripe.');
        }

        $secret = Config::string('cashier.secret');

        if ($secret === '' || $secret === self::SENTINEL_SECRET) {
            $this->markTestSkipped('STRIPE_SECRET não configurado.');
        }

        if (Plan::purchasable() === []) {
            $this->markTestSkipped('Nenhum plano tem price id configurado.');
        }
    }

    protected function tearDown(): void
    {
        $secret = Config::string('cashier.secret');

        if ($secret !== '' && $this->customersCreated !== []) {
            $client = new StripeClient($secret);

            foreach ($this->customersCreated as $customer) {
                // Falha aqui não deve derrubar o teste: o objetivo da limpeza é
                // não deixar lixo, não afirmar nada.
                rescue(fn () => $client->customers->delete($customer), report: false);
            }
        }

        parent::tearDown();
    }

    public function test_stripe_accepts_the_credentials(): void
    {
        $client = new StripeClient(Config::string('cashier.secret'));

        $balance = $client->balance->retrieve();

        $this->assertNotEmpty($balance->available);
    }

    /**
     * The whole point of this file: that a checkout session is really created,
     * with a real price, and comes back as a Stripe-hosted URL.
     */
    public function test_a_checkout_session_is_created_for_a_real_price(): void
    {
        $plan = array_values(Plan::purchasable())[0];

        $team = Team::factory()->create(['is_personal' => false]);

        $checkout = $team->newSubscription(TeamBilling::SUBSCRIPTION, (string) $plan->priceId)
            ->trialDays(TeamBilling::for($team)->trialDays())
            ->checkout([
                'success_url' => 'https://example.test/ok',
                'cancel_url' => 'https://example.test/cancel',
            ]);

        $team->refresh();

        $this->assertIsString($team->stripe_id);
        $this->customersCreated[] = $team->stripe_id;

        // O url vem da sessão da Stripe por trás do Checkout; a propriedade
        // mágica funciona em runtime mas não se declara.
        $this->assertStringStartsWith(
            'https://checkout.stripe.com/',
            (string) $checkout->asStripeCheckoutSession()->url,
        );
    }

    /**
     * The price shown and the price charged come from different places.
     *
     * The plans page reads config; Stripe charges whatever the price object
     * says. Nothing reconciles the two, so raising a price in Stripe without
     * touching config makes the product quote a number it will not charge.
     * This is the only place that can notice, because it is the only one that
     * can see both.
     */
    public function test_every_plan_charges_what_it_advertises(): void
    {
        $client = new StripeClient(Config::string('cashier.secret'));

        foreach (Plan::purchasable() as $plan) {
            $price = $client->prices->retrieve((string) $plan->priceId);

            $this->assertSame(
                $plan->price,
                $price->unit_amount,
                "O plano [{$plan->key}] anuncia {$plan->price} e a Stripe cobra {$price->unit_amount}.",
            );

            $this->assertSame(
                strtolower(Config::string('cashier.currency')),
                strtolower((string) $price->currency),
                "O plano [{$plan->key}] está numa moeda diferente da configurada.",
            );
        }
    }

    public function test_the_billing_portal_returns_a_hosted_url(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);

        $team->createAsStripeCustomer();
        $team->refresh();

        $this->assertIsString($team->stripe_id);
        $this->customersCreated[] = $team->stripe_id;

        $url = $team->billingPortalUrl('https://example.test/back');

        $this->assertStringStartsWith('https://billing.stripe.com/', $url);
    }

    /**
     * The one part of a plan change that fixtures cannot answer: whether Stripe
     * accepts it and the subscription really moves. Proration is money, and no
     * offline test exercises the arithmetic.
     */
    public function test_a_subscription_really_moves_between_plans(): void
    {
        $plans = array_values(Plan::purchasable());

        if (count($plans) < 2) {
            $this->markTestSkipped('São necessários dois planos com price id para trocar entre eles.');
        }

        [$from, $to] = $plans;

        $team = Team::factory()->create(['is_personal' => false]);
        $team->createAsStripeCustomer();
        $this->assertIsString($team->stripe_id);
        $this->customersCreated[] = $team->stripe_id;

        $team->newSubscription(TeamBilling::SUBSCRIPTION, (string) $from->priceId)
            ->trialDays(14)
            ->add();

        resolve(SwapPlan::class)->handle($team->refresh(), $to);

        $this->assertSame($to->priceId, $team->refresh()->subscription(TeamBilling::SUBSCRIPTION)?->stripe_price);
    }

    /**
     * The gap `InvoicesTest` documents but cannot close: offline, the call to
     * Stripe dies on authentication before Cashier's owner check ever runs, so
     * every cross-team download looks like a 500 from the sentinel key, not
     * the 403 this is actually about.
     *
     * With a real key there is no such shortcut. A subscription with a test
     * card attached (`pm_card_visa`, Stripe's own no-op success token — test
     * mode, no money moves) bills immediately and leaves a real invoice
     * behind. A second team, authenticated and permitted to manage its own
     * billing, asks for that invoice through `billing.invoice` and must be
     * refused: the invoice belongs to a customer it is not.
     */
    public function test_a_team_cannot_download_another_teams_invoice(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $plan = array_values(Plan::purchasable())[0];

        $owner = Team::factory()->create(['is_personal' => false]);
        $owner->createAsStripeCustomer();
        $this->assertIsString($owner->stripe_id);
        $this->customersCreated[] = $owner->stripe_id;
        $owner->updateDefaultPaymentMethod('pm_card_visa');

        $owner->newSubscription(TeamBilling::SUBSCRIPTION, (string) $plan->priceId)->add();

        $invoice = $owner->invoices()->first();

        if ($invoice === null) {
            $this->markTestSkipped('A assinatura não gerou fatura imediata para testar o download.');
        }

        $intruder = Team::factory()->create(['is_personal' => false]);
        $intruder->createAsStripeCustomer();
        $this->assertIsString($intruder->stripe_id);
        $this->customersCreated[] = $intruder->stripe_id;

        $user = User::factory()->create();
        $intruder->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($intruder, TeamRole::Owner);
        $user->switchTeam($intruder);

        // A propriedade mágica vem do objeto Stripe por trás do Invoice;
        // funciona em runtime mas não se declara, como no checkout acima.
        $this->actingAs($user)
            ->get(route('billing.invoice', ['invoice' => $invoice->asStripeInvoice()->id]))
            ->assertForbidden();
    }
}
