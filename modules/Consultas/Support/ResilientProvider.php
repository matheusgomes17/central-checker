<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Closure;
use Modules\Consultas\Contracts\ProviderContract;
use Throwable;

/**
 * Wraps a provider with the resilience every provider should have and none
 * should write: it consults the breaker before calling, retries only on a
 * technical failure, turns a thrown exception into a Failed result, and feeds
 * the breaker so a source that is down stops being hammered.
 *
 * NoData is never retried and never counts against the breaker — it is a real
 * answer. Only Failed does both.
 */
final class ResilientProvider
{
    /**
     * Tentativas no total (não retentativas): 2 = uma repetição.
     */
    public const int MAX_TRIES = 2;

    /**
     * @param  (Closure(int): void)|null  $sleep
     */
    public function __construct(
        private readonly ProviderContract $inner,
        private readonly CircuitBreaker $breaker,
        private readonly int $maxTries = self::MAX_TRIES,
        private readonly ?Closure $sleep = null,
    ) {}

    public function execute(ConsultaInput $input): ProviderResult
    {
        $key = $this->inner->key();

        if ($this->breaker->isOpen($key)) {
            return ProviderResult::failed('circuito aberto');
        }

        $result = ProviderResult::failed('não executado');

        for ($attempt = 1; $attempt <= $this->maxTries; $attempt++) {
            $result = $this->attempt($input);

            if (! $result->isFailed()) {
                if ($result->isSuccess()) {
                    $this->breaker->recordSuccess($key);
                }

                return $result;
            }

            $this->breaker->recordFailure($key);

            if ($attempt < $this->maxTries) {
                $this->backoff($attempt);
            }
        }

        return $result;
    }

    private function attempt(ConsultaInput $input): ProviderResult
    {
        try {
            return $this->inner->execute($input);
        } catch (Throwable $e) {
            return ProviderResult::failed($e->getMessage());
        }
    }

    private function backoff(int $attempt): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($attempt);

            return;
        }

        // Espera crescente entre tentativas, em milissegundos. Sob requisição
        // síncrona o breaker é a proteção principal; a repetição é para a falha
        // transitória, não para insistir num provider fora do ar.
        usleep($attempt * 200_000);
    }
}
