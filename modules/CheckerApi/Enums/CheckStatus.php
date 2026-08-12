<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Enums;

/**
 * The manual availability the operator sets on a check.
 *
 * Separate from the circuit breaker: this is the human decision, the breaker is
 * the automatic one. A check is offered only when both agree.
 */
enum CheckStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
}
