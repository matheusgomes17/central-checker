<?php

declare(strict_types=1);

namespace Modules\Consultas\Enums;

/**
 * The manual availability the operator sets on a consulta.
 *
 * Separate from the circuit breaker: this is the human decision, the breaker is
 * the automatic one. A consulta is offered only when both agree.
 */
enum ConsultaStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
}
