<?php

declare(strict_types=1);

use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Contracts\WebhookVerifier;
use Modules\Payments\Gateways\VirtualPayGateway;
use Modules\Payments\Webhooks\VirtualPayWebhookVerifier;

it('resolve WebhookVerifier para a implementação VirtualPay', function (): void {
    expect(resolve(WebhookVerifier::class))->toBeInstanceOf(VirtualPayWebhookVerifier::class);
});

it('resolve PaymentGateway para a implementação VirtualPay', function (): void {
    expect(resolve(PaymentGateway::class))->toBeInstanceOf(VirtualPayGateway::class);
});

it('registra ambos os contratos como singletons', function (): void {
    expect(resolve(WebhookVerifier::class))->toBe(resolve(WebhookVerifier::class))
        ->and(resolve(PaymentGateway::class))->toBe(resolve(PaymentGateway::class));
});
