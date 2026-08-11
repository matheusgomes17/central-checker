<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Exceptions;

use RuntimeException;

/**
 * Raised when a recharge amount falls outside the configured bounds.
 */
final class RechargeAmountException extends RuntimeException
{
    public static function outOfRange(int $amountCents, int $min, int $max): self
    {
        return new self("Valor de recarga [{$amountCents}] fora do intervalo permitido: {$min} a {$max} centavos.");
    }
}
