<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * The billing states the product has to behave differently in.
 *
 * Cashier answers with a handful of overlapping true/false answers — subscribed, on
 * trial, past due, on grace period — and reading them at each call site is how
 * two screens end up disagreeing about the same account. This is the single
 * reading of them.
 */
enum SubscriptionStatus: string
{
    /** Nunca assinou, ou o período pago acabou. */
    case None = 'none';

    /** Testando, sem cartão. */
    case Trialing = 'trialing';

    /** Pagando e em dia. */
    case Active = 'active';

    /** Cobrança falhou e a Stripe ainda está tentando. */
    case PastDue = 'past_due';

    /** Cancelou, mas o período já pago ainda não acabou. */
    case Cancelling = 'cancelling';

    /** Acabou de vez. */
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No subscription'),
            self::Trialing => __('Trial'),
            self::Active => __('Active'),
            self::PastDue => __('Payment failing'),
            self::Cancelling => __('Cancelling'),
            self::Ended => __('Ended'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active, self::Trialing => 'success',
            self::PastDue => 'danger',
            self::Cancelling => 'warning',
            self::None, self::Ended => 'gray',
        };
    }

    /**
     * Whether the product should be nudging the team to act.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::PastDue, self::Cancelling, self::Ended], true);
    }
}
