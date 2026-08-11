<?php

declare(strict_types=1);

use Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier;

it('aceita a assinatura exata que o gateway-virtual produz (Spatie WebhookServer)', function (): void {
    $secret = 'segredo-de-teste';
    $payload = [
        'id' => 'evt-1',
        'event' => 'deposit.paid',
        'occurred_at' => '2026-08-05T05:29:02-03:00',
        'data' => [
            'transaction_id' => 'tx-1',
            'type' => 'deposit',
            'status' => 'paid',
            'amount' => '10.00',
            'currency' => 'BRL',
            'reference' => 'RECHARGE-1-abc',
        ],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', (string) $body, $secret);

    expect(new VirtualPayWebhookVerifier($secret)->verify((string) $body, $signature))->toBeTrue();
});

it('aceita o corpo com unicode não escapado, como o gateway realmente envia', function (): void {
    $secret = 'segredo-de-teste';
    $payload = [
        'id' => 'evt-2',
        'event' => 'deposit.paid',
        'occurred_at' => '2026-08-05T05:29:02-03:00',
        'data' => [
            'transaction_id' => 'tx-2',
            'type' => 'deposit',
            'status' => 'paid',
            'amount' => '10.00',
            'currency' => 'BRL',
            'reference' => 'RECHARGE-2-abc',
            'description' => 'Depósito rápido',
        ],
    ];

    // JSON_UNESCAPED_UNICODE é o que o WebhookServer usa para assinar. Se o
    // corpo fosse re-serializado sem essa flag (escapando "ó" como ó), o
    // HMAC não bateria mais — a verificação precisa dos bytes crus recebidos,
    // nunca de um array reconstruído.
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', (string) $body, $secret);

    expect(new VirtualPayWebhookVerifier($secret)->verify((string) $body, $signature))->toBeTrue();

    $escapedBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $escapedSignature = hash_hmac('sha256', (string) $escapedBody, $secret);

    expect(new VirtualPayWebhookVerifier($secret)->verify((string) $body, $escapedSignature))->toBeFalse();
});
