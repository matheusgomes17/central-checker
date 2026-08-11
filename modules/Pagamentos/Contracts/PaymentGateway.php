<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Contracts;

use Modules\Pagamentos\Support\GatewayCharge;
use Modules\Pagamentos\Support\PayerData;

/**
 * What a payment gateway implementation must provide to create Pix charges.
 */
interface PaymentGateway
{
    public function createPixCharge(int $amountCents, string $reference, PayerData $payer): GatewayCharge;
}
