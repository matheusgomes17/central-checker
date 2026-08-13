<?php

declare(strict_types=1);

namespace Modules\Payments\Actions;

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Enums\PaymentPurpose;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Exceptions\RechargeAmountException;
use Modules\Payments\Models\PaymentIntent;
use Modules\Payments\Support\PayerData;

/**
 * Starts a wallet recharge: validates the amount, opens a pending
 * PaymentIntent, and asks the gateway for a Pix charge.
 *
 * Does not credit the wallet — that only happens when the webhook confirms
 * the payment actually landed.
 */
class CreateRecharge
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly PaymentGateway $gateway,
    ) {}

    public function handle(Team $team, int $amountCents, PayerData $payer): PaymentIntent
    {
        $min = Config::integer('billing.recharge.min_cents');
        $max = Config::integer('billing.recharge.max_cents');
        $expiration = Config::integer('billing.recharge.expiration_seconds');

        if ($amountCents < $min || $amountCents > $max) {
            throw RechargeAmountException::outOfRange($amountCents, $min, $max);
        }

        return $this->tenants->forTenant($team, function () use ($team, $amountCents, $payer, $expiration): PaymentIntent {
            $reference = 'RECHARGE-'.$team->id.'-'.Str::uuid()->toString();

            $intent = PaymentIntent::query()->create([
                'reference' => $reference,
                'amount_cents' => $amountCents,
                'status' => PaymentStatus::Pending,
                'method' => 'pix',
                'purpose' => PaymentPurpose::Recharge,
                'expires_at' => now()->addSeconds($expiration),
            ]);

            $charge = $this->gateway->createPixCharge($amountCents, $reference, $payer);

            $intent->update([
                'external_id' => $charge->externalId(),
                'copy_paste' => $charge->copyPaste(),
                'qr_code' => $charge->qrCode(),
            ]);

            return $intent->refresh();
        });
    }
}
