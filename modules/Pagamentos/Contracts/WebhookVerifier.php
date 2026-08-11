<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Contracts;

/**
 * What verifies that an incoming webhook payload really came from the gateway.
 */
interface WebhookVerifier
{
    public function verify(string $rawBody, string $signature): bool;
}
