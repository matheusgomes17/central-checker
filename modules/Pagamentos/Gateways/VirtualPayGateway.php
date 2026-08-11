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
final readonly class VirtualPayGateway implements PaymentGateway
{
    public function __construct(
        private string $baseUrl,
        private string $clientId,
        private string $clientSecret,
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
            $message = $response->json('message');
            $error = $response->json('error');

            $reason = match (true) {
                is_string($message) => $message,
                is_string($error) => $error,
                default => 'erro desconhecido',
            };

            throw PaymentGatewayException::from($response->status(), $reason);
        }

        $id = $response->json('id');
        $copyPaste = $response->json('copy_paste');
        $qrCode = $response->json('qr_code');
        $status = $response->json('status', 'pending');

        if (! is_string($id) || ! is_string($copyPaste) || ! is_string($status) || ($qrCode !== null && ! is_string($qrCode))) {
            throw PaymentGatewayException::from($response->status(), 'resposta do gateway em formato inesperado');
        }

        return GatewayCharge::from($id, $copyPaste, $qrCode, $status);
    }
}
