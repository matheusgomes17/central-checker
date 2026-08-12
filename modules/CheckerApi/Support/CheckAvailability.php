<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Support;

use Modules\CheckerApi\Enums\CheckStatus;
use Modules\CheckerApi\Models\Check;

/**
 * Whether a check may run right now.
 *
 * Kept out of the model so the model stays a thin data holder: availability is
 * two things agreeing — the operator's manual status and the breaker being
 * closed for the provider behind it.
 */
final readonly class CheckAvailability
{
    public function __construct(private CircuitBreaker $breaker) {}

    public function available(Check $check): bool
    {
        if ($check->status !== CheckStatus::Active) {
            return false;
        }

        return ! $this->breaker->isOpen($check->provider_key);
    }
}
