<?php

declare(strict_types=1);

namespace Modules\Carteira\Exceptions;

use RuntimeException;

final class HoldNotActiveException extends RuntimeException
{
    public static function forId(int $id): self
    {
        return new self("O hold [{$id}] não está ativo.");
    }
}
