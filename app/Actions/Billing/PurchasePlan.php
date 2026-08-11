<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\TenantManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Pagamentos\Contracts\PaymentGateway;
use Modules\Pagamentos\Enums\PaymentPurpose;
use Modules\Pagamentos\Enums\PaymentStatus;
use Modules\Pagamentos\Models\PaymentIntent;
use Modules\Pagamentos\Support\PayerData;

/**
 * Starts a plan purchase: opens a pending PaymentIntent for the plan's price
 * and asks the gateway for a Pix charge.
 *
 * Mirrors `Modules\Pagamentos\Actions\CreateRecharge`, but purpose=plan and
 * the amount comes from the plan's price rather than a caller-supplied
 * amount. Does not activate the subscription — that only happens once the
 * webhook confirms the payment actually landed (Task 6).
 */
class PurchasePlan
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly PaymentGateway $gateway,
    ) {}

    public function handle(Team $team, Plan $plan, PayerData $payer): PaymentIntent
    {
        if ($plan->price <= 0) {
            throw new InvalidArgumentException("O plano [{$plan->key}] não pode ser comprado.");
        }

        return $this->tenants->forTenant($team, function () use ($team, $plan, $payer): PaymentIntent {
            $reference = 'PLAN-'.$plan->key.'-'.$team->id.'-'.Str::uuid()->toString();

            $intent = PaymentIntent::query()->create([
                'reference' => $reference,
                'amount_cents' => $plan->price,
                'status' => PaymentStatus::Pending,
                'method' => 'pix',
                'purpose' => PaymentPurpose::Plan,
                'plan' => $plan->key,
                'expires_at' => now()->addSeconds(Config::integer('billing.recharge.expiration_seconds')),
            ]);

            $charge = $this->gateway->createPixCharge($plan->price, $reference, $payer);

            $intent->update([
                'external_id' => $charge->externalId(),
                'copy_paste' => $charge->copyPaste(),
                'qr_code' => $charge->qrCode(),
            ]);

            return $intent->refresh();
        });
    }
}
