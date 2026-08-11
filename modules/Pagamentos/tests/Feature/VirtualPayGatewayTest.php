<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Pagamentos\Exceptions\PaymentGatewayException;
use Modules\Pagamentos\Gateways\VirtualPayGateway;
use Modules\Pagamentos\Support\PayerData;

function gateway(): VirtualPayGateway
{
    return new VirtualPayGateway('https://api.virtualpay.online/v2', 'cid', 'csecret');
}

it('cria a cobrança Pix e mapeia a resposta', function (): void {
    Http::fake(['*/transactions' => Http::response([
        'id' => 'uuid-1', 'copy_paste' => '000201...', 'qr_code' => 'base64==', 'status' => 'pending',
    ], 201)]);

    $charge = gateway()->createPixCharge(1000, 'RECHARGE-1-123', PayerData::for('Fulano', 'f@e.com'));

    expect($charge->externalId())->toBe('uuid-1')->and($charge->copyPaste())->toBe('000201...');

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'deposit'
        && $request['payment_method'] === 'pix'
        && $request['amount'] === '10.00'          // centavos -> reais decimais
        && $request['reference'] === 'RECHARGE-1-123'
        && $request->hasHeader('x-client-secret', 'csecret')
        // PayerData::for() sem document/phone não deve inventar as chaves no corpo.
        && ! array_key_exists('document', $request->data())
        && ! array_key_exists('phone', $request->data()));
});

it('lança em erro do gateway', function (): void {
    Http::fake(['*/transactions' => Http::response(['message' => 'invalid'], 422)]);

    gateway()->createPixCharge(1000, 'RECHARGE-1-123', PayerData::for('Fulano', 'f@e.com'));
})->throws(PaymentGatewayException::class);
