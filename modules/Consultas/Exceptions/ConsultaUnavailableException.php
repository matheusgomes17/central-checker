<?php

declare(strict_types=1);

namespace Modules\Consultas\Exceptions;

use Modules\Consultas\Models\Consulta;
use RuntimeException;

final class ConsultaUnavailableException extends RuntimeException
{
    public static function for(Consulta $consulta): self
    {
        return new self("A consulta [{$consulta->slug}] está indisponível no momento.");
    }
}
