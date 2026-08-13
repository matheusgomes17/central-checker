<?php

declare(strict_types=1);

use Modules\Payments\Webhooks\VirtualPayWebhookVerifier;

it('aceita assinatura HMAC-SHA256 hex do corpo cru', function (): void {
    $secret = 'whsec_teste';
    $body = '{"event":"deposit.paid","data":{"status":"paid"}}';
    $signature = hash_hmac('sha256', $body, $secret);

    expect(new VirtualPayWebhookVerifier($secret)->verify($body, $signature))->toBeTrue();
});

it('recusa assinatura de outro segredo', function (): void {
    $body = '{"a":1}';
    $wrong = hash_hmac('sha256', $body, 'outro');

    expect(new VirtualPayWebhookVerifier('whsec_teste')->verify($body, $wrong))->toBeFalse();
});

it('recusa quando o corpo foi adulterado', function (): void {
    $secret = 'whsec_teste';
    $signature = hash_hmac('sha256', '{"amount":"10.00"}', $secret);

    expect(new VirtualPayWebhookVerifier($secret)->verify('{"amount":"999.00"}', $signature))->toBeFalse();
});

it('fail-closed: secret vazio recusa tudo', function (): void {
    $body = '{"a":1}';
    expect(new VirtualPayWebhookVerifier('')->verify($body, hash_hmac('sha256', $body, '')))->toBeFalse();
});
