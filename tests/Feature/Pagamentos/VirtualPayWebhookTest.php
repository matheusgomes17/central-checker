<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Models\Wallet;
use Modules\Pagamentos\Contracts\WebhookVerifier;
use Modules\Pagamentos\Enums\PaymentStatus;
use Modules\Pagamentos\Models\PaymentIntent;
use Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier;

beforeEach(function (): void {
    $this->secret = 'whsec_test';

    // A vinculação de WebhookVerifier é a Task 8 (ainda não feita), então o
    // teste vincula manualmente para exercitar o controller isoladamente.
    app()->bind(WebhookVerifier::class, fn () => new VirtualPayWebhookVerifier($this->secret));
});

/**
 * Posts a signed VirtualPay webhook with the raw body sent verbatim — the
 * signature is computed over the exact bytes, so building it through
 * $this->post() (which re-encodes) would break the HMAC.
 *
 * @param  array<string, mixed>  $payload
 */
function postSignedWebhook(array $payload, ?string $secret = null): Illuminate\Testing\TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body, $secret ?? test()->secret);

    return test()->call(
        'POST',
        '/webhooks/virtualpay',
        [],
        [],
        [],
        ['HTTP_X_GATEWAY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

it('credita a carteira exatamente pelo amount_cents do intent e marca Paid', function (): void {
    $team = Team::factory()->create();
    $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_123')->create(['amount_cents' => 5000]);

    $response = postSignedWebhook([
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

    expect($intent->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($intent->fresh()->paid_at)->not->toBeNull()
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(5000);

    $transaction = Wallet::forTeam($team)->transactions()->latest()->first();
    expect($transaction->reference_type)->toBe($intent->getMorphClass())
        ->and($transaction->reference_id)->toBe($intent->getKey())
        ->and($transaction->amount_cents)->toBe(5000);
});

it('não credita de novo ao reentregar o mesmo webhook', function (): void {
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

    postSignedWebhook($payload)->assertOk();
    $second = postSignedWebhook($payload);

    $second->assertOk();

    resolve(TenantManager::class)->set($team);

    expect(Wallet::forTeam($team)->balance_cents)->toBe(5000);
});

it('assinatura inválida devolve 401 e não credita nada', function (): void {
    $team = Team::factory()->create();
    $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_789')->create(['amount_cents' => 5000]);

    $response = postSignedWebhook([
        'event' => 'deposit.paid',
        'data' => [
            'status' => 'paid',
            'transaction_id' => 'tx_789',
            'reference' => $intent->reference,
        ],
    ], secret: 'segredo_errado');

    $response->assertStatus(401);

    resolve(TenantManager::class)->set($team);

    expect($intent->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(0);
});

it('evento desconhecido ou status diferente de paid devolve 200 sem creditar', function (): void {
    $team = Team::factory()->create();
    $intent = PaymentIntent::factory()->for($team)->withExternalId('tx_evt')->create(['amount_cents' => 5000]);

    $unknownEvent = postSignedWebhook([
        'event' => 'deposit.created',
        'data' => [
            'status' => 'paid',
            'transaction_id' => 'tx_evt',
            'reference' => $intent->reference,
        ],
    ]);

    $unknownEvent->assertOk();

    $notPaid = postSignedWebhook([
        'event' => 'deposit.paid',
        'data' => [
            'status' => 'pending',
            'transaction_id' => 'tx_evt',
            'reference' => $intent->reference,
        ],
    ]);

    $notPaid->assertOk();

    resolve(TenantManager::class)->set($team);

    expect($intent->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(0);
});

it('transaction_id/reference desconhecido devolve 200 sem creditar', function (): void {
    $team = Team::factory()->create();
    PaymentIntent::factory()->for($team)->withExternalId('tx_known')->create(['amount_cents' => 5000]);

    $response = postSignedWebhook([
        'event' => 'deposit.paid',
        'data' => [
            'status' => 'paid',
            'transaction_id' => 'tx_unknown',
            'reference' => 'RECHARGE-DOES-NOT-EXIST',
        ],
    ]);

    $response->assertOk();

    resolve(TenantManager::class)->set($team);

    expect(Wallet::forTeam($team)->balance_cents)->toBe(0);
});
