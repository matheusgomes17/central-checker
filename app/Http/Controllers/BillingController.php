<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the team to Stripe and brings it back.
 *
 * Checkout and the customer portal are hosted by Stripe on purpose: card data
 * never reaches this application, which is the difference between a boilerplate
 * anyone can deploy and one that drags PCI scope along with it.
 */
class BillingController extends Controller
{
    public function checkout(Request $request, string $plan): Responsable|RedirectResponse
    {
        $team = $this->authorizedTeam();

        // Quem já assina troca pela tela, que passa pela action e pela
        // confirmação. Chegar aqui assinado significa link antigo ou URL
        // digitada — e seguir criaria uma segunda assinatura.
        if (TeamBilling::for($team)->subscribed()) {
            return back()->withErrors(['plan' => __('This team already has a subscription. Change the plan from the billing page.')]);
        }

        $selected = Plan::purchasable()[$plan] ?? null;

        if ($selected === null || $selected->priceId === null) {
            return back()->withErrors(['plan' => __('That plan is not available.')]);
        }

        $checkout = $team->newSubscription(TeamBilling::SUBSCRIPTION, $selected->priceId);

        // O teste é sem cartão: a Stripe só o aplica se pedirmos, e pedir
        // depois de a assinatura existir seria tarde.
        if (! $team->subscribed(TeamBilling::SUBSCRIPTION) && TeamBilling::for($team)->trialDays() > 0) {
            $checkout->trialDays(TeamBilling::for($team)->trialDays());
        }

        try {
            return $checkout->checkout([
                'success_url' => route('billing.edit'),
                'cancel_url' => route('billing.edit'),
            ]);
        } catch (IncompletePayment $e) {
            // O id vem do PaymentIntent por trás do Payment; ler pela
            // propriedade mágica funciona em runtime mas não se declara.
            return to_route('cashier.payment', [
                $e->payment->asStripePaymentIntent()->id,
                'redirect' => route('billing.edit'),
            ]);
        }
    }

    public function portal(Request $request): SymfonyRedirectResponse|RedirectResponse
    {
        $team = $this->authorizedTeam();

        if ($team->stripe_id === null) {
            return back()->withErrors(['billing' => __('This team has no billing account yet.')]);
        }

        return $team->redirectToBillingPortal(route('billing.edit'));
    }

    public function invoice(Request $request, string $invoice): Response
    {
        $team = $this->authorizedTeam();

        // downloadInvoice() e não findInvoice(): só o primeiro passa pelo
        // findInvoiceOrFail, que é onde o Cashier confere o dono e devolve 403.
        return $team->downloadInvoice($invoice, [
            'vendor' => config('app.name'),
            'product' => __('Subscription'),
        ]);
    }

    /**
     * The current team, if the person may spend its money.
     */
    private function authorizedTeam(): Team
    {
        $team = resolve(TenantManager::class)->current();

        abort_if($team === null, 404);

        $user = Auth::user();

        abort_unless(
            $user !== null && $user->hasTeamPermission($team, TeamPermission::ManageBilling),
            403,
        );

        return $team;
    }
}
