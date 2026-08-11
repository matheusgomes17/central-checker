<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Billing\PaymentFailed;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the events Cashier does not handle on its own.
 *
 * Cashier keeps the subscription rows in step with Stripe, which is the part
 * that must not be written again here. What it does not do is tell anyone when a
 * charge fails — and a failed charge that nobody hears about is a cancellation
 * three retries from now.
 */
class StripeWebhookController extends CashierWebhookController
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleInvoicePaymentFailed(array $payload): Response
    {
        $team = $this->teamFor($payload);

        if (! $team instanceof Team) {
            return $this->successMethod();
        }

        $recipients = $this->billingContactsOf($team);

        if ($recipients !== []) {
            Notification::send($recipients, new PaymentFailed(
                $team,
                $this->intFrom($payload, 'data.object.amount_due', 0),
                $this->intFrom($payload, 'data.object.attempt_count', 1),
            ));
        }

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function teamFor(array $payload): ?Team
    {
        $customer = data_get($payload, 'data.object.customer');

        if (! is_string($customer)) {
            return null;
        }

        // Consulta direta em vez de Cashier::findBillable(): o retorno de lá é
        // o contrato Billable, que nem sempre é um Team para o analisador.
        return Team::query()->where('stripe_id', $customer)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intFrom(array $payload, string $key, int $default): int
    {
        $value = data_get($payload, $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Who should hear that the card failed.
     *
     * The people who can do something about it: whoever holds the permission
     * to manage billing. Telling every member would leak the state of the
     * account to people who cannot fix it.
     *
     * @return array<int, User>
     */
    private function billingContactsOf(Team $team): array
    {
        return $team->members()
            ->get()
            ->filter(fn (User $member): bool => $member->hasTeamPermission($team, TeamPermission::ManageBilling))
            ->values()
            ->all();
    }
}
