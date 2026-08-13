<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

/**
 * What a PaymentIntent is paying for.
 */
enum PaymentPurpose: string
{
    case Recharge = 'recharge';

    /**
     * The plan-purchase purpose.
     */
    case Plan = 'plan';
}
