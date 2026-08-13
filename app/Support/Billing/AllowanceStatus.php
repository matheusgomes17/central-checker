<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * A team's usage allowance for one check, in the current period.
 *
 * `quota` mirrors `PlanCheck::quota`: null means unlimited, an int means the
 * plan includes that many successful executions before the period resets.
 */
final readonly class AllowanceStatus
{
    public function __construct(public ?int $quota, public int $used) {}

    public function isUnlimited(): bool
    {
        return $this->quota === null;
    }

    /**
     * How many executions are left in the period, or null when unlimited.
     */
    public function remaining(): ?int
    {
        return $this->quota === null ? null : max(0, $this->quota - $this->used);
    }

    public function hasRemaining(): bool
    {
        return $this->quota === null || $this->used < $this->quota;
    }
}
