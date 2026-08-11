<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Webhooks;

use Modules\Pagamentos\Contracts\WebhookVerifier;

/**
 * Verifies VirtualPay's X-Gateway-Signature: an HMAC-SHA256, hex-encoded, over
 * the raw request body, keyed by the webhook secret.
 *
 * The raw body must be the exact bytes received, via $request->getContent(),
 * never a re-encoded array — re-encoding reorders/repacks and breaks the HMAC.
 * Fail-closed: with no secret configured, every payload is rejected, because an
 * unverified payment webhook is the number-one fraud hole (the legacy had it).
 */
final readonly class VirtualPayWebhookVerifier implements WebhookVerifier
{
    public function __construct(private string $secret) {}

    public function verify(string $rawBody, string $signature): bool
    {
        if ($this->secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $this->secret), $signature);
    }
}
