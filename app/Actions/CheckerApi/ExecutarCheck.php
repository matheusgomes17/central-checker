<?php

declare(strict_types=1);

namespace App\Actions\CheckerApi;

use App\Exceptions\TenantIsMissingException;
use App\Models\Team;
use App\Models\User;
use App\Models\UserCheckOverride;
use App\Support\Billing\Franquia;
use App\Support\Billing\FranquiaStatus;
use App\Support\TenantManager;
use Modules\Carteira\Actions\ConfirmHold;
use Modules\Carteira\Actions\ReleaseHold;
use Modules\Carteira\Actions\ReserveCredit;
use Modules\Carteira\Models\WalletHold;
use Modules\CheckerApi\Exceptions\CheckBlockedForUserException;
use Modules\CheckerApi\Exceptions\CheckUnavailableException;
use Modules\CheckerApi\Exceptions\FranquiaExhaustedException;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\CheckAvailability;
use Modules\CheckerApi\Support\CheckInput;
use Modules\CheckerApi\Support\CircuitBreaker;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ResilientProvider;
use Throwable;

/**
 * Runs one check, charges for it only when it returns useful data, and
 * records the outcome.
 *
 * The order is the point: reserve first, so an execution that cannot be paid
 * for never reaches the provider; then settle the reservation into a charge on
 * success, or release it on no_data / failure / any error. The reserve holds
 * the money across the slow provider call so two runs cannot spend the same
 * balance.
 */
class ExecutarCheck
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly CheckAvailability $availability,
        private readonly CircuitBreaker $breaker,
        private readonly TenantManager $tenants,
        private readonly ReserveCredit $reserveCredit,
        private readonly ConfirmHold $confirmHold,
        private readonly ReleaseHold $releaseHold,
        private readonly Franquia $franquia,
    ) {}

    public function handle(Check $check, CheckInput $input, ?User $user = null): CheckExecution
    {
        if (! $this->availability->available($check)) {
            throw CheckUnavailableException::for($check);
        }

        $team = $this->tenants->current();

        if (! $team instanceof Team) {
            throw TenantIsMissingException::forModel(CheckExecution::class);
        }

        // A resolução do override roda no contexto do tenant já fixado acima.
        $override = $user instanceof User ? UserCheckOverride::for($team, $user, $check) : null;

        if ($override instanceof UserCheckOverride && $override->blocked) {
            throw CheckBlockedForUserException::for($check);
        }

        $hold = null;
        $consumesFranquia = false;

        if ($override instanceof UserCheckOverride && $override->granted) {
            // Comp: grátis, ganha de plano/franquia/preço — não consome a franquia do plano.
        } else {
            $price = $override instanceof UserCheckOverride
                ? $override->price_cents ?? $check->price_cents
                : $check->price_cents;
            $franquia = $this->franquia->for($team, $check);

            if ($franquia instanceof FranquiaStatus && $franquia->hasRemaining()) {
                // Plano cobre e há franquia (ilimitado ou dentro do teto) — grátis sob o plano.
                $consumesFranquia = true;
            } elseif ($price > 0) {
                // Não coberto, ou franquia esgotada, mas há preço em crédito — cobra crédito.
                $hold = $this->reserveCredit->handle($team, $price);
            } elseif ($franquia instanceof FranquiaStatus) {
                // Coberto pelo plano, franquia esgotada, e o check não tem preço em crédito — bloqueia.
                throw FranquiaExhaustedException::for($check);
            }
            // else: não coberto e preço 0 — grátis.
        }

        try {
            $provider = $this->registry->resolve($check->provider_key);
            $resilient = new ResilientProvider($provider, $this->breaker);

            $startedAt = hrtime(true);
            $result = $resilient->execute($input);
            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

            $execution = CheckExecution::create([
                'check_id' => $check->id,
                'user_id' => $user?->id,
                'outcome' => $result->outcome(),
                'consumed_franquia' => $consumesFranquia,
                'duration_ms' => $durationMs,
                'input_masked' => $input->masked(),
                'result' => $result->isSuccess() ? $result->data() : null,
                'failure_reason' => $result->reason(),
            ]);

            $this->settle($hold, $result->isSuccess(), $check, $execution);

            return $execution;
        } catch (Throwable $e) {
            $this->release($hold);

            throw $e;
        }
    }

    private function settle(?WalletHold $hold, bool $success, Check $check, CheckExecution $execution): void
    {
        if (! $hold instanceof WalletHold) {
            return;
        }

        if ($success) {
            $this->confirmHold->handle($hold, "Check: {$check->slug}", $execution);

            return;
        }

        $this->releaseHold->handle($hold);
    }

    private function release(?WalletHold $hold): void
    {
        if ($hold instanceof WalletHold) {
            $this->releaseHold->handle($hold);
        }
    }
}
