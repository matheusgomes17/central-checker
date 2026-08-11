<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Enums;

/**
 * The life of a PaymentIntent, from creation to settlement.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Failed = 'failed';
}
