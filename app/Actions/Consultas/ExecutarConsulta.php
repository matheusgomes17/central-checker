<?php

declare(strict_types=1);

namespace App\Actions\Consultas;

use App\Models\User;
use Modules\Consultas\Exceptions\ConsultaUnavailableException;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Models\ConsultaExecution;
use Modules\Consultas\Support\CircuitBreaker;
use Modules\Consultas\Support\ConsultaAvailability;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ResilientProvider;

/**
 * Runs one consulta and records the outcome.
 *
 * Lives in app/ because it is the composition point: it is the only place that
 * touches both the Consultas module's engine and the tenant-scoped execution
 * record. It does not charge — billing is a later phase; here we run and write
 * down what happened, every outcome included, because the provider success rate
 * report needs the failures too.
 */
class ExecutarConsulta
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ConsultaAvailability $availability,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function handle(Consulta $consulta, ConsultaInput $input, ?User $user = null): ConsultaExecution
    {
        if (! $this->availability->available($consulta)) {
            throw ConsultaUnavailableException::for($consulta);
        }

        $provider = $this->registry->resolve($consulta->provider_key);
        $resilient = new ResilientProvider($provider, $this->breaker);

        $startedAt = hrtime(true);
        $result = $resilient->execute($input);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return ConsultaExecution::create([
            'consulta_id' => $consulta->id,
            'user_id' => $user?->id,
            'outcome' => $result->outcome(),
            'duration_ms' => $durationMs,
            'input_masked' => $input->masked(),
            'result' => $result->isSuccess() ? $result->data() : null,
            'failure_reason' => $result->reason(),
        ]);
    }
}
