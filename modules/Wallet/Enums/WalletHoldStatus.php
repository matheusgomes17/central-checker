<?php

declare(strict_types=1);

namespace Modules\Wallet\Enums;

/**
 * The life of a reservation: held, then either settled into a charge or
 * released back to the available balance.
 */
enum WalletHoldStatus: string
{
    case Active = 'active';
    case Settled = 'settled';
    case Released = 'released';
}
