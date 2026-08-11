<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Enums;

/**
 * What a PaymentIntent is paying for.
 *
 * Only wallet recharge exists today; plan purchase joins this enum in a
 * later phase (Phase D).
 */
enum PaymentPurpose: string
{
    case Recharge = 'recharge';
}
