<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Billing\ActivatePlan;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Payments\Contracts\WebhookVerifier;
use Modules\Payments\Enums\PaymentPurpose;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\PaymentIntent;
use Modules\Wallet\Actions\CreditWallet;
use Modules\Wallet\Enums\WalletTransactionType;

/**
 * Receives VirtualPay's confirmation that a Pix charge was paid and credits
 * the wallet — the point where the Payments and Wallet modules meet, which
 * is why it lives in app/ instead of either module.
 *
 * Fraud-critical: fail-closed on the signature (an unverified request never
 * credits anything), and idempotent under a row lock (a retried delivery of
 * an already-paid intent credits nothing a second time).
 */
class VirtualPayWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly TenantManager $tenants,
        private readonly CreditWallet $creditWallet,
        private readonly ActivatePlan $activatePlan,
    ) {}

    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Gateway-Signature', '');

        if (! $this->verifier->verify($raw, $signature)) {
            return response('', 401);
        }

        $payload = json_decode($raw, true);
        $event = data_get($payload, 'event');
        $status = data_get($payload, 'data.status');
        $externalId = data_get($payload, 'data.transaction_id');
        $reference = data_get($payload, 'data.reference');

        if (! in_array($event, ['deposit.paid', 'transaction.updated'], true) || $status !== 'paid') {
            return response('', 200);
        }

        if (blank($externalId) && blank($reference)) {
            // Sem os dois, where('external_id', null) viraria whereNull() e
            // casaria com qualquer intent pendente que ainda não tem
            // external_id — um evento malformado (mas assinado) poderia
            // creditar um intent arbitrário. Sem identificador, não é nosso.
            return response('', 200);
        }

        $intent = PaymentIntent::acrossTenants()
            ->where(function (\Illuminate\Contracts\Database\Query\Builder $query) use ($externalId, $reference): void {
                // Cada predicado só entra se o identificador existir — um
                // where('external_id', null) vira whereNull() e, combinado
                // com o OR, casaria com qualquer intent pendente sem
                // external_id ainda (ver guard acima e o teste que prova).
                if (filled($externalId)) {
                    $query->orWhere('external_id', $externalId);
                }

                if (filled($reference)) {
                    $query->orWhere('reference', $reference);
                }
            })
            ->first();

        if (! $intent instanceof PaymentIntent) {
            return response('', 200);
        }

        $this->tenants->forTenant($intent->team, function () use ($intent): void {
            // getConnection()->transaction() em vez da fachada DB: o teste de
            // arquitetura proíbe controllers de tocar o banco pela fachada
            // diretamente — a transação em si continua igual.
            $intent->getConnection()->transaction(function () use ($intent): void {
                $locked = PaymentIntent::query()->whereKey($intent->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status === PaymentStatus::Paid) {
                    // Idempotente: já processado, não credita de novo.
                    return;
                }

                $team = $locked->team;

                if (! $team instanceof Team) {
                    // team_id é obrigatório na migração; isto não deveria
                    // acontecer, mas a relação é nullable para o analisador.
                    return;
                }

                $locked->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

                match ($locked->purpose) {
                    PaymentPurpose::Recharge => $this->creditWallet->handle(
                        $team,
                        $locked->amount_cents, // credita o que NÓS cobramos, não o valor do payload
                        WalletTransactionType::Recharge,
                        "Recarga Pix — {$locked->reference}",
                        $locked,
                    ),
                    PaymentPurpose::Plan => $this->activatePlanIfResolvable($team, $locked),
                };
            });
        });

        return response('', 200);
    }

    /**
     * Activates the plan the intent paid for, unless the plan key no longer
     * resolves (removed or renamed after the charge was created).
     *
     * Deliberately does not throw: this runs inside the transaction that
     * already marked the intent Paid, and Plan::find() throwing here would
     * roll that update back too — reverting the intent to Pending, 500ing the
     * webhook, and leaving the gateway retrying forever for a customer who
     * already paid. Staying Paid without a subscription is a state a human
     * can reconcile; an endless retry loop is not.
     */
    private function activatePlanIfResolvable(Team $team, PaymentIntent $intent): void
    {
        $plan = Plan::tryFind((string) $intent->plan);

        if (! $plan instanceof Plan) {
            Log::warning('Pix de plano pago com plano desconhecido; intent marcado Paid sem ativar.', [
                'intent_id' => $intent->id,
                'plan' => $intent->plan,
            ]);

            return;
        }

        $this->activatePlan->handle($team, $plan);
    }
}
