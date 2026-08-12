<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Exceptions;

use Modules\CheckerApi\Models\Check;
use RuntimeException;

final class CheckUnavailableException extends RuntimeException
{
    public static function for(Check $check): self
    {
        return new self("O check [{$check->slug}] está indisponível no momento.");
    }
}
