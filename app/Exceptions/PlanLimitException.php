<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Billing\Plan;
use RuntimeException;

/**
 * Raised when an action would take a team past what its plan allows.
 *
 * The message names the limit and the plan, because "upgrade to continue" with
 * no numbers is the kind of wall people write support tickets about.
 */
class PlanLimitException extends RuntimeException
{
    public static function reached(string $resource, Plan $plan, int $limit): self
    {
        return new self(__('Your :plan plan allows :limit :resource. Upgrade to add more.', [
            'plan' => $plan->name,
            'limit' => $limit,
            'resource' => $resource,
        ]));
    }
}
