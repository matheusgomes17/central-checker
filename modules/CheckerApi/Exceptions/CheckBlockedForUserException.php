<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Exceptions;

use Modules\CheckerApi\Models\Check;
use RuntimeException;

/**
 * Thrown when a UserCheckOverride blocks the given user from running a
 * check, regardless of what the team's plan or credit would otherwise allow.
 */
final class CheckBlockedForUserException extends RuntimeException
{
    public static function for(Check $check): self
    {
        return new self("O usuário está bloqueado de rodar o check [{$check->slug}].");
    }
}
