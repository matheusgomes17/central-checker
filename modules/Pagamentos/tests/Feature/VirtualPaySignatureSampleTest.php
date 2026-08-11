<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier;

beforeEach(function (): void {
    // Config::string() lança exceção quando o valor é null (env não setado),
    // em vez de string vazia — usa Config::get() com cast para preservar o
    // skip-guard descrito no brief mesmo nesse caso.
    $secret = (string) Config::get('services.virtualpay.webhook_secret', '');
    if ($secret === '') {
        $this->markTestSkipped('Defina VIRTUALPAY_WEBHOOK_SECRET com o segredo real para confirmar o esquema.');
    }
    $this->secret = $secret;
});

it('reproduz a assinatura do webhook real registrado no log', function (): void {
    // Corpo cru compacto exato da amostra real (Content-Length 403).
    $body = '{"id":"8d8dc76b-aa39-4584-b25b-2d4984e6af29","data":{"fee":"0.60","type":"deposit","amount":"10.00","e2e_id":"E20855875202608050828L99D6YB6MTG","status":"paid","currency":"BRL","reference":"RECHARGE-9-1785918510","net_amount":"9.40","description":"Recarga de saldo - Fenix Center","transaction_id":"50a45b82-3d77-48d8-a4db-5c0263303402"},"event":"deposit.paid","occurred_at":"2026-08-05T05:29:02-03:00"}';
    $realSignature = '7d9b5e154565fe48953bc614f6e751207cc68477815c3a0e0f3ad149929908fa';

    expect((new VirtualPayWebhookVerifier($this->secret))->verify($body, $realSignature))->toBeTrue();
});
