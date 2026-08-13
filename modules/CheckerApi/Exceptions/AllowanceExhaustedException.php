<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Exceptions;

use Modules\CheckerApi\Models\Check;
use RuntimeException;

final class AllowanceExhaustedException extends RuntimeException
{
    public static function for(Check $check): self
    {
        return new self("A franquia do plano para [{$check->slug}] acabou e o check não aceita crédito.");
    }
}
