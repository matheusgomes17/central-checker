<?php

declare(strict_types=1);

namespace Modules\Carteira\Exceptions;

use RuntimeException;

final class InsufficientBalanceException extends RuntimeException
{
    public static function because(int $available, int $requested): self
    {
        return new self("Saldo insuficiente: disponível {$available}, pedido {$requested} (em centavos).");
    }
}
