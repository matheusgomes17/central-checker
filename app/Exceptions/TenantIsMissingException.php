<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant aware row would be written with no team to own it.
 *
 * Failing loudly here is the whole point. Writing null instead would produce a
 * row no tenant can ever see again, and that surfaces days later as missing
 * data rather than as the bug it is.
 */
class TenantIsMissingException extends RuntimeException
{
    /**
     * @param  class-string  $model
     */
    public static function forModel(string $model): self
    {
        return new self(
            "Não há tenant no contexto para criar [{$model}]. ".
            'Defina o tenant com TenantManager::set(), rode dentro de '.
            'TenantManager::forTenant(), ou informe team_id explicitamente.',
        );
    }
}
