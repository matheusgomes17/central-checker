<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Support;

use Modules\CheckerApi\Contracts\ProviderContract;
use Modules\CheckerApi\Exceptions\ProviderNotRegisteredException;

/**
 * Maps a provider key to its implementation.
 *
 * Mirrors ResourceCounters: a new data source is a new class plus one line of
 * registration, and asking for a key nobody registered is an error rather than
 * a silent null — a check pointing at a missing provider is a bug we want
 * loud, not a screen that quietly does nothing.
 */
class ProviderRegistry
{
    /**
     * @var array<string, ProviderContract>
     */
    private array $providers = [];

    public function register(ProviderContract $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function resolve(string $key): ProviderContract
    {
        return $this->providers[$key] ?? throw ProviderNotRegisteredException::forKey($key);
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
