<?php

declare(strict_types=1);

namespace Modules\Payments\Exceptions;

use RuntimeException;

/**
 * Raised when the payment gateway rejects or fails a request.
 */
final class PaymentGatewayException extends RuntimeException
{
    public static function from(int $status, string $message): self
    {
        return new self("Gateway de pagamento respondeu {$status}: {$message}");
    }
}
