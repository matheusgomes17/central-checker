<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use Modules\Payments\Support\GatewayCharge;
use Modules\Payments\Support\PayerData;

/**
 * What a payment gateway implementation must provide to create Pix charges.
 */
interface PaymentGateway
{
    public function createPixCharge(int $amountCents, string $reference, PayerData $payer): GatewayCharge;
}
