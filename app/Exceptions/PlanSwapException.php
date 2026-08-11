<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Billing\Plan;
use RuntimeException;

/**
 * Raised when a plan change cannot be applied to the subscription it names.
 *
 * Separate from PlanLimitException and PlanFeatureException because the answer
 * is a third one: not "you have used all of them" and not "your plan never had
 * this", but "this change does not apply".
 */
class PlanSwapException extends RuntimeException
{
    public static function samePlan(Plan $plan): self
    {
        return new self(__('This team is already on the :plan plan.', ['plan' => $plan->name]));
    }

    public static function notSubscribed(): self
    {
        return new self(__('This team has no subscription to change.'));
    }

    /**
     * The destination plan has no Stripe price, so there is nothing to swap
     * the subscription to — distinct from "no subscription", which is about
     * the team, not the plan.
     */
    public static function notPurchasable(Plan $plan): self
    {
        return new self(__('The :plan plan is not available for purchase.', ['plan' => $plan->name]));
    }
}
