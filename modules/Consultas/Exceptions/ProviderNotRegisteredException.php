<?php

declare(strict_types=1);

namespace Modules\Consultas\Exceptions;

use RuntimeException;

final class ProviderNotRegisteredException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Nenhum provider registrado para a chave [{$key}].");
    }
}
