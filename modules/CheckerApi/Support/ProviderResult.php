<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Support;

use Modules\CheckerApi\Enums\ProviderOutcome;

/**
 * The immutable outcome of a single provider call.
 *
 * Built through the named constructors so an impossible state — a Failed result
 * carrying data, or a Success with no data — cannot be assembled.
 */
final readonly class ProviderResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private ProviderOutcome $outcome,
        private array $data,
        private ?string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(array $data): self
    {
        return new self(ProviderOutcome::Success, $data, null);
    }

    public static function noData(): self
    {
        return new self(ProviderOutcome::NoData, [], null);
    }

    public static function failed(string $reason): self
    {
        return new self(ProviderOutcome::Failed, [], $reason);
    }

    public function outcome(): ProviderOutcome
    {
        return $this->outcome;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function isSuccess(): bool
    {
        return $this->outcome === ProviderOutcome::Success;
    }

    public function isNoData(): bool
    {
        return $this->outcome === ProviderOutcome::NoData;
    }

    public function isFailed(): bool
    {
        return $this->outcome === ProviderOutcome::Failed;
    }
}
