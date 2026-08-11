<?php

declare(strict_types=1);

use Modules\Pagamentos\Contracts\PaymentGateway;
use Modules\Pagamentos\Contracts\WebhookVerifier;
use Modules\Pagamentos\Gateways\VirtualPayGateway;
use Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier;

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
