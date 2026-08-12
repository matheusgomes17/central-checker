<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Support;

use Modules\CheckerApi\Contracts\ProviderContract;
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
     * @param  list<ProviderResult>  $results
     */
    private function __construct(
        private readonly array $results,
        private readonly ?Throwable $throws = null,
        private string $key = 'fake',
        private int $calls = 0,
    ) {}

    public static function returning(ProviderResult ...$results): self
    {
        $results = array_values($results);

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

    public function execute(CheckInput $input): ProviderResult
    {
        $index = $this->calls;
        $this->calls++;

        if ($this->throws instanceof Throwable) {
            throw $this->throws;
        }

        // Repete o último resultado quando as chamadas passam do combinado, para
        // que um teste de retry não precise enfileirar um resultado por tentativa.
        // Índice por count() em vez de array_key_last(): este último devolve
        // int|string|null, e o null (lista vazia) não é uma chave válida aqui —
        // returning() sempre garante ao menos um resultado; throwing() já
        // lançou/retornou antes de chegar nesta linha.
        $lastIndex = count($this->results) - 1;

        return $this->results[$index] ?? $this->results[$lastIndex];
    }

    public function healthCheck(): bool
    {
        return ! $this->throws instanceof Throwable;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
