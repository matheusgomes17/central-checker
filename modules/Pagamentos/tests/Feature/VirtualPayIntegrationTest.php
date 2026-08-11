<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\Pagamentos\Gateways\VirtualPayGateway;
use Modules\Pagamentos\Support\PayerData;

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
beforeEach(function (): void {
    if (! Config::boolean('billing.virtualpay_integration')) {
        $this->markTestSkipped('Defina VIRTUALPAY_INTEGRATION=true para rodar contra a VirtualPay.');
    }

    $secret = (string) Config::get('services.virtualpay.client_secret', '');

    if ($secret === '') {
        $this->markTestSkipped('VIRTUALPAY_CLIENT_SECRET não configurado.');
    }
});

it('cria uma cobrança Pix real de valor mínimo', function (): void {
    $gateway = new VirtualPayGateway(
        (string) Config::get('services.virtualpay.base_url'),
        (string) Config::get('services.virtualpay.client_id'),
        (string) Config::get('services.virtualpay.client_secret'),
    );

    $charge = $gateway->createPixCharge(
        Config::integer('billing.recharge.min_cents'),
        'INTEGRATION-TEST-'.uniqid(),
        PayerData::for('Teste Integração', 'integracao@example.test'),
    );

    expect($charge->externalId())->not->toBeEmpty()
        ->and($charge->copyPaste())->not->toBeEmpty();
});
