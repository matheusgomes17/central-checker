<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Modules\Consultas\Enums\ConsultaStatus;
use Modules\Consultas\Models\Consulta;

/**
 * Whether a consulta may run right now.
 *
 * Kept out of the model so the model stays a thin data holder: availability is
 * two things agreeing — the operator's manual status and the breaker being
 * closed for the provider behind it.
 */
final class ConsultaAvailability
{
    public function __construct(private readonly CircuitBreaker $breaker) {}

    public function available(Consulta $consulta): bool
    {
        if ($consulta->status !== ConsultaStatus::Active) {
            return false;
        }

        return ! $this->breaker->isOpen($consulta->provider_key);
    }
}
