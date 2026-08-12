<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Contracts;

use Modules\CheckerApi\Support\CheckInput;
use Modules\CheckerApi\Support\ProviderResult;

/**
 * What a third party writes to plug a data source into the platform.
 *
 * Deliberately small: the provider owns only the call and the parsing of its
 * own response into a ProviderResult. Timeout, retry and the circuit breaker
 * live in the ResilientProvider that wraps this, so no provider reimplements
 * resilience its own way and hands us its own bugs.
 */
interface ProviderContract
{
    public function key(): string;

    public function execute(CheckInput $input): ProviderResult;

    public function healthCheck(): bool;
}
