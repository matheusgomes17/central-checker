<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Trips a provider offline after it fails too many times in a row.
 *
 * State lives in the cache, not in the consulta row: writing "under maintenance"
 * back to the database on every failed call would couple reads to writes and
 * race under concurrency. Availability is computed instead — the manual status
 * of the catalog plus this breaker being closed.
 *
 * After the cooldown the key is simply allowed through again (half-open): the
 * next call decides. A success clears the count; a failure trips it once more.
 */
class CircuitBreaker
{
    /**
     * Falhas seguidas que abrem o circuito.
     */
    public const int FAILURE_THRESHOLD = 5;

    /**
     * Segundos que o circuito permanece aberto antes de deixar passar de novo.
     */
    public const int COOLDOWN_SECONDS = 60;

    public function recordSuccess(string $key): void
    {
        Cache::forget($this->failuresKey($key));
        Cache::forget($this->openKey($key));
    }

    public function recordFailure(string $key): void
    {
        $failures = (int) Cache::get($this->failuresKey($key), 0) + 1;

        Cache::put($this->failuresKey($key), $failures, now()->addSeconds(self::COOLDOWN_SECONDS));

        if ($failures >= self::FAILURE_THRESHOLD) {
            Cache::put($this->openKey($key), true, now()->addSeconds(self::COOLDOWN_SECONDS));
        }
    }

    public function isOpen(string $key): bool
    {
        return (bool) Cache::get($this->openKey($key), false);
    }

    private function failuresKey(string $key): string
    {
        return "consultas:breaker:failures:{$key}";
    }

    private function openKey(string $key): string
    {
        return "consultas:breaker:open:{$key}";
    }
}
