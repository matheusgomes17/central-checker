<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Modules\Consultas\Contracts\ProviderContract;
use Throwable;

/**
 * A provider that returns canned results, for tests and local demos.
 *
 * Ships in Support rather than in the test suite because the registry, the
 * seeder and the local environment all need to reference it by class, and the
 * module's test directory is not on the autoload path.
 */
final class FakeProvider implements ProviderContract
{
    /**
     * @param  array<int, ProviderResult>  $results
     */
    private function __construct(
        private readonly array $results,
        private readonly ?Throwable $throws = null,
        private string $key = 'fake',
        private int $calls = 0,
    ) {}

    public static function returning(ProviderResult ...$results): self
    {
        return new self($results === [] ? [ProviderResult::noData()] : $results);
    }

    public static function throwing(Throwable $e): self
    {
        return new self([], $e);
    }

    public function withKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function execute(ConsultaInput $input): ProviderResult
    {
        $index = $this->calls;
        $this->calls++;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        // Repete o último resultado quando as chamadas passam do combinado, para
        // que um teste de retry não precise enfileirar um resultado por tentativa.
        return $this->results[$index] ?? $this->results[array_key_last($this->results)];
    }

    public function healthCheck(): bool
    {
        return $this->throws === null;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
