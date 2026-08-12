<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Support;

use Closure;
use Modules\CheckerApi\Contracts\ProviderContract;
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
final readonly class ResilientProvider
{
    /**
     * Tentativas no total (não retentativas): 2 = uma repetição.
     */
    public const int MAX_TRIES = 2;

    /**
     * @param  (Closure(int): void)|null  $backoff
     */
    public function __construct(
        private ProviderContract $inner,
        private CircuitBreaker $breaker,
        private int $maxTries = self::MAX_TRIES,
        private ?Closure $backoff = null,
    ) {}

    public function execute(CheckInput $input): ProviderResult
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

    private function attempt(CheckInput $input): ProviderResult
    {
        try {
            return $this->inner->execute($input);
        } catch (Throwable $e) {
            return ProviderResult::failed($e->getMessage());
        }
    }

    private function backoff(int $attempt): void
    {
        if ($this->backoff instanceof Closure) {
            ($this->backoff)($attempt);

            return;
        }

        // Espera crescente entre tentativas, em milissegundos. Sob requisição
        // síncrona o breaker é a proteção principal; a repetição é para a falha
        // transitória, não para insistir num provider fora do ar.
        \Illuminate\Support\Sleep::usleep($attempt * 200_000);
    }
}
