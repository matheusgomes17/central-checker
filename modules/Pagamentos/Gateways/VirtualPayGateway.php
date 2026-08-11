<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Gateways;

use Illuminate\Support\Facades\Http;
use Modules\Pagamentos\Contracts\PaymentGateway;
use Modules\Pagamentos\Exceptions\PaymentGatewayException;
use Modules\Pagamentos\Support\GatewayCharge;
use Modules\Pagamentos\Support\PayerData;

/**
 * HTTP driver for VirtualPay: creates a Pix charge via POST /transactions.
 */
final class VirtualPayGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function createPixCharge(int $amountCents, string $reference, PayerData $payer): GatewayCharge
    {
        $body = [
            'type' => 'deposit',
            'payment_method' => 'pix',
            'amount' => number_format($amountCents / 100, 2, '.', ''),
            'description' => $reference,
            'reference' => $reference,
            'email' => $payer->email(),
            'name' => $payer->name(),
        ];

        if ($payer->document() !== null) {
            $body['document'] = $payer->document();
        }

        if ($payer->phone() !== null) {
            $body['phone'] = $payer->phone();
        }

        $response = Http::withHeaders([
            'x-client-id' => $this->clientId,
            'x-client-secret' => $this->clientSecret,
        ])->acceptJson()->post("{$this->baseUrl}/transactions", $body);

        if ($response->failed()) {
            $json = $response->json();

            $message = $json['message'] ?? $json['error'] ?? 'erro desconhecido';

            throw PaymentGatewayException::from($response->status(), (string) $message);
        }

        $json = $response->json();

        return GatewayCharge::from($json['id'], $json['copy_paste'], $json['qr_code'] ?? null, $json['status'] ?? 'pending');
    }
}
