<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\Payments\Gateways\VirtualPayGateway;
use Modules\Payments\Support\PayerData;

/**
 * The one part of VirtualPay that fixtures cannot answer: whether the real API
 * accepts what we send it.
 *
 * Opt-in on purpose. A suite that quietly calls a payment provider is a suite
 * that fails when the network does and takes seconds per test — so this runs
 * only when asked:
 *
 *     VIRTUALPAY_INTEGRATION=true php artisan test --filter=VirtualPayIntegration
 *
 * It needs VIRTUALPAY_CLIENT_ID and VIRTUALPAY_CLIENT_SECRET.
 */
it('cria uma cobrança Pix real de valor mínimo', function (): void {
    $gateway = new VirtualPayGateway(
        Config::string('services.virtualpay.base_url'),
        Config::string('services.virtualpay.client_id'),
        Config::string('services.virtualpay.client_secret'),
    );

    $charge = $gateway->createPixCharge(
        Config::integer('billing.recharge.min_cents'),
        'INTEGRATION-TEST-'.uniqid(),
        PayerData::for('Teste Integração', 'integracao@example.test'),
    );

    expect($charge->externalId())->not->toBeEmpty()
        ->and($charge->copyPaste())->not->toBeEmpty();
})->skip(
    fn (): bool => ! Config::boolean('billing.virtualpay_integration') || Config::string('services.virtualpay.client_secret') === '',
    'Defina VIRTUALPAY_INTEGRATION=true e VIRTUALPAY_CLIENT_SECRET para rodar contra a VirtualPay.',
);
