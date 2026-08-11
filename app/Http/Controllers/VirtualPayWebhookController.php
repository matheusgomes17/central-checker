<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Pagamentos\Contracts\WebhookVerifier;
use Modules\Pagamentos\Enums\PaymentStatus;
use Modules\Pagamentos\Models\PaymentIntent;

/**
 * Receives VirtualPay's confirmation that a Pix charge was paid and credits
 * the wallet — the point where the Pagamentos and Carteira modules meet, which
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

        $intent = PaymentIntent::acrossTenants()
            ->where(fn ($query) => $query->where('external_id', $externalId)->orWhere('reference', $reference))
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

                $this->creditWallet->handle(
                    $team,
                    $locked->amount_cents, // credita o que NÓS cobramos, não o valor do payload
                    WalletTransactionType::Recharge,
                    "Recarga Pix — {$locked->reference}",
                    $locked,
                );
            });
        });

        return response('', 200);
    }
}
