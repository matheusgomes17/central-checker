<?php

declare(strict_types=1);

namespace App\Actions\Consultas;

use App\Exceptions\TenantIsMissingException;
use App\Models\User;
use App\Support\TenantManager;
use Modules\Carteira\Actions\ConfirmHold;
use Modules\Carteira\Actions\ReleaseHold;
use Modules\Carteira\Actions\ReserveCredit;
use Modules\Carteira\Models\WalletHold;
use Modules\Consultas\Exceptions\ConsultaUnavailableException;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Models\ConsultaExecution;
use Modules\Consultas\Support\CircuitBreaker;
use Modules\Consultas\Support\ConsultaAvailability;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ResilientProvider;
use Throwable;

/**
 * Runs one consulta, charges for it only when it returns useful data, and
 * records the outcome.
 *
 * The order is the point: reserve first, so an execution that cannot be paid
 * for never reaches the provider; then settle the reservation into a charge on
 * success, or release it on no_data / failure / any error. The reserve holds
 * the money across the slow provider call so two runs cannot spend the same
 * balance.
 */
class ExecutarConsulta
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ConsultaAvailability $availability,
        private readonly CircuitBreaker $breaker,
        private readonly TenantManager $tenants,
        private readonly ReserveCredit $reserveCredit,
        private readonly ConfirmHold $confirmHold,
        private readonly ReleaseHold $releaseHold,
    ) {}

    public function handle(Consulta $consulta, ConsultaInput $input, ?User $user = null): ConsultaExecution
    {
        if (! $this->availability->available($consulta)) {
            throw ConsultaUnavailableException::for($consulta);
        }

        $team = $this->tenants->current();

        if ($team === null) {
            throw TenantIsMissingException::forModel(ConsultaExecution::class);
        }

        $hold = $consulta->price_cents > 0
            ? $this->reserveCredit->handle($team, $consulta->price_cents)
            : null;

        try {
            $provider = $this->registry->resolve($consulta->provider_key);
            $resilient = new ResilientProvider($provider, $this->breaker);

            $startedAt = hrtime(true);
            $result = $resilient->execute($input);
            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

            $execution = ConsultaExecution::create([
                'consulta_id' => $consulta->id,
                'user_id' => $user?->id,
                'outcome' => $result->outcome(),
                'duration_ms' => $durationMs,
                'input_masked' => $input->masked(),
                'result' => $result->isSuccess() ? $result->data() : null,
                'failure_reason' => $result->reason(),
            ]);

            $this->settle($hold, $result->isSuccess(), $consulta, $execution);

            return $execution;
        } catch (Throwable $e) {
            $this->release($hold);

            throw $e;
        }
    }

    private function settle(?WalletHold $hold, bool $success, Consulta $consulta, ConsultaExecution $execution): void
    {
        if ($hold === null) {
            return;
        }

        if ($success) {
            $this->confirmHold->handle($hold, "Consulta: {$consulta->slug}", $execution);

            return;
        }

        $this->releaseHold->handle($hold);
    }

    private function release(?WalletHold $hold): void
    {
        if ($hold !== null) {
            $this->releaseHold->handle($hold);
        }
    }
}
