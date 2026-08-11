<?php

declare(strict_types=1);

namespace Modules\Carteira\Enums;

/**
 * How a ledger line moved money.
 *
 * isCredit() marks the types that add to the balance; a Charge subtracts. The
 * distinction is what keeps a recharge from being written with a negative
 * amount, or a charge with a positive one.
 */
enum WalletTransactionType: string
{
    case Recharge = 'recharge';
    case Charge = 'charge';
    case Refund = 'refund';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';

    public function isCredit(): bool
    {
        return match ($this) {
            self::Recharge, self::Bonus, self::Adjustment, self::Refund => true,
            self::Charge => false,
        };
    }
}
