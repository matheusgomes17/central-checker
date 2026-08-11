<?php

declare(strict_types=1);

namespace Tests\Feature\Pagamentos;

use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\Subscriptions;
use App\Support\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletTransaction;
use Modules\Pagamentos\Contracts\WebhookVerifier;
use Modules\Pagamentos\Enums\PaymentPurpose;
use Modules\Pagamentos\Enums\PaymentStatus;
use Modules\Pagamentos\Models\PaymentIntent;
use Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class VirtualPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();

        // O binding real (Task 8) lê o secret de config/services.php, que em
        // teste é string vazia; aqui é preciso um secret conhecido para computar
        // a assinatura HMAC, então o teste sobrescreve com um valor fixo.
        $this->app->bind(WebhookVerifier::class, fn (): VirtualPayWebhookVerifier => new VirtualPayWebhookVerifier(self::SECRET));
    }

    public function test_it_credits_the_wallet_by_exactly_the_intent_amount_cents_and_marks_paid(): void
    {
        $team = Team::factory()->create();
        $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_123')->create(['amount_cents' => 5000]);

        $response = $this->postSignedWebhook([
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
                'transaction_id' => 'tx_123',
                'reference' => $intent->reference,
                'amount' => '999.00',
            ],
        ]);

        $response->assertOk();

        resolve(TenantManager::class)->set($team);

        $this->assertSame(PaymentStatus::Paid, $intent->fresh()->status);
        $this->assertNotNull($intent->fresh()->paid_at);
        $this->assertSame(5000, Wallet::forTeam($team)->balance_cents);

        $transaction = Wallet::forTeam($team)->transactions()->latest()->first();
        $this->assertSame($intent->getMorphClass(), $transaction->reference_type);
        $this->assertSame($intent->getKey(), $transaction->reference_id);
        $this->assertSame(5000, $transaction->amount_cents);
    }

    public function test_it_does_not_credit_again_when_the_same_webhook_is_redelivered(): void
    {
        $team = Team::factory()->create();
        $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_456')->create(['amount_cents' => 5000]);

        $payload = [
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
                'transaction_id' => 'tx_456',
                'reference' => $intent->reference,
            ],
        ];

        $this->postSignedWebhook($payload)->assertOk();
        $second = $this->postSignedWebhook($payload);

        $second->assertOk();

        resolve(TenantManager::class)->set($team);

        $this->assertSame(5000, Wallet::forTeam($team)->balance_cents);
    }

    public function test_an_invalid_signature_returns_401_and_credits_nothing(): void
    {
        $team = Team::factory()->create();
        $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_789')->create(['amount_cents' => 5000]);

        $response = $this->postSignedWebhook([
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
                'transaction_id' => 'tx_789',
                'reference' => $intent->reference,
            ],
        ], secret: 'segredo_errado');

        $response->assertStatus(401);

        resolve(TenantManager::class)->set($team);

        $this->assertSame(PaymentStatus::Pending, $intent->fresh()->status);
        $this->assertSame(0, Wallet::forTeam($team)->balance_cents);
    }

    public function test_an_unknown_event_or_a_status_other_than_paid_returns_200_without_crediting(): void
    {
        $team = Team::factory()->create();
        $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_evt')->create(['amount_cents' => 5000]);

        $unknownEvent = $this->postSignedWebhook([
            'event' => 'deposit.created',
            'data' => [
                'status' => 'paid',
                'transaction_id' => 'tx_evt',
                'reference' => $intent->reference,
            ],
        ]);

        $unknownEvent->assertOk();

        $notPaid = $this->postSignedWebhook([
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'pending',
                'transaction_id' => 'tx_evt',
                'reference' => $intent->reference,
            ],
        ]);

        $notPaid->assertOk();

        resolve(TenantManager::class)->set($team);

        $this->assertSame(PaymentStatus::Pending, $intent->fresh()->status);
        $this->assertSame(0, Wallet::forTeam($team)->balance_cents);
    }

    public function test_an_event_without_transaction_id_and_without_reference_returns_200_and_credits_no_intent(): void
    {
        $team = Team::factory()->create();
        // Sem external_id: é justamente o intent que where('external_id', null)
        // casaria por engano se o guard de identificador ausente não existisse.
        $intent = PaymentIntent::factory()->for($team)->create(['amount_cents' => 5000]);

        $response = $this->postSignedWebhook([
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
            ],
        ]);

        $response->assertOk();

        resolve(TenantManager::class)->set($team);

        $this->assertSame(PaymentStatus::Pending, $intent->fresh()->status);
        $this->assertSame(0, Wallet::forTeam($team)->balance_cents);
    }

    public function test_an_unknown_transaction_id_or_reference_returns_200_without_crediting(): void
    {
        $team = Team::factory()->create();
        PaymentIntent::factory()->for($team)->withExternalId('tx_known')->create(['amount_cents' => 5000]);

        $response = $this->postSignedWebhook([
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
                'transaction_id' => 'tx_unknown',
                'reference' => 'RECHARGE-DOES-NOT-EXIST',
            ],
        ]);

        $response->assertOk();

        resolve(TenantManager::class)->set($team);

        $this->assertSame(0, Wallet::forTeam($team)->balance_cents);
    }

    public function test_a_reference_only_event_does_not_match_an_unrelated_pending_intent_without_external_id(): void
    {
        $teamA = Team::factory()->create();
        // O órfão: pendente, sem external_id ainda (gateway não respondeu).
        // É exatamente o intent que where('external_id', null) casaria por
        // engano se virasse whereNull() dentro do OR.
        $orphan = PaymentIntent::factory()->for($teamA)->create(['amount_cents' => 5000]);

        $teamB = Team::factory()->create();

        $response = $this->postSignedWebhook([
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
                'reference' => 'RECHARGE-DOES-NOT-EXIST',
            ],
        ]);

        $response->assertOk();

        resolve(TenantManager::class)->set($teamA);

        $this->assertSame(PaymentStatus::Pending, $orphan->fresh()->status);
        $this->assertSame(0, Wallet::forTeam($teamA)->balance_cents);

        resolve(TenantManager::class)->set($teamB);
        $this->assertSame(0, Wallet::forTeam($teamB)->balance_cents);
    }

    public function test_a_plan_payment_activates_the_subscription_and_credits_no_wallet(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::find('pro');
        $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_plan')->create([
            'purpose' => PaymentPurpose::Plan,
            'plan' => 'pro',
            'amount_cents' => $plan->price,
        ]);

        $response = $this->postSignedWebhook([
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
                'transaction_id' => 'tx_plan',
                'reference' => $intent->reference,
            ],
        ]);

        $response->assertOk();

        resolve(TenantManager::class)->set($team);

        $this->assertSame(PaymentStatus::Paid, $intent->fresh()->status);

        $subscription = resolve(Subscriptions::class)->active($team);
        $this->assertSame('pro', $subscription?->plan);
        $this->assertTrue($subscription->current_period_end->isFuture());

        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_replaying_a_plan_payment_does_not_extend_the_subscription_again(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::find('pro');
        $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_plan_replay')->create([
            'purpose' => PaymentPurpose::Plan,
            'plan' => 'pro',
            'amount_cents' => $plan->price,
        ]);

        $payload = [
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
                'transaction_id' => 'tx_plan_replay',
                'reference' => $intent->reference,
            ],
        ];

        $this->postSignedWebhook($payload)->assertOk();

        resolve(TenantManager::class)->set($team);
        $originalEnd = resolve(Subscriptions::class)->active($team)?->current_period_end;

        $second = $this->postSignedWebhook($payload);
        $second->assertOk();

        resolve(TenantManager::class)->set($team);

        $this->assertSame(PaymentStatus::Paid, $intent->fresh()->status);
        $this->assertEquals($originalEnd, resolve(Subscriptions::class)->active($team)?->current_period_end);
    }

    public function test_a_plan_payment_with_an_unknown_plan_key_is_marked_paid_without_activating_or_crashing(): void
    {
        $team = Team::factory()->create();
        $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_ghost_plan')->create([
            'purpose' => PaymentPurpose::Plan,
            'plan' => 'ghost',
            'amount_cents' => 1000,
        ]);

        $response = $this->postSignedWebhook([
            'event' => 'deposit.paid',
            'data' => [
                'status' => 'paid',
                'transaction_id' => 'tx_ghost_plan',
                'reference' => $intent->reference,
            ],
        ]);

        $response->assertOk();

        resolve(TenantManager::class)->set($team);

        $this->assertSame(PaymentStatus::Paid, $intent->fresh()->status);
        $this->assertSame(0, PlanSubscription::query()->count());
    }

    /**
     * Posts a signed VirtualPay webhook with the raw body sent verbatim — the
     * signature is computed over the exact bytes, so building it through
     * $this->post() (which re-encodes) would break the HMAC.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function postSignedWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, $secret ?? self::SECRET);

        return $this->call(
            'POST',
            '/webhooks/virtualpay',
            server: ['HTTP_X_GATEWAY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }
}
