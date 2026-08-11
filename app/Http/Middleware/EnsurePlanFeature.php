<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\PlanFeatureException;
use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route whose capability the team's plan does not include.
 *
 * Two questions, both asked here, both at request time: does the plan the team
 * is entitled to right now include this, and failing that, has support granted
 * it to this team specifically. The second is what lets a promise be honored
 * without anyone editing the plan configuration for a single account.
 *
 * They stay separate because Pennant's database store remembers the first
 * answer it resolved. Folding the plan into the flag would freeze a decision
 * that changes every time a subscription starts, lapses or is cancelled.
 *
 * Must run after whatever resolves the tenant. The capability is scoped to the
 * team, and on the API the team comes from the token — asked earlier, it would
 * answer about nobody.
 */
class EnsurePlanFeature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $team = resolve(TenantManager::class)->current();

        // Deixa passar de propósito. Requisição sem tenant não tem plano a
        // consultar, e recusar aqui seria recusar por não saber — toda rota
        // que carrega recurso resolve o tenant antes desta verificação, então
        // chegar sem time significa que não há nada de pago em jogo. O que
        // guarda essa premissa é o teste de ordem de middleware; se ela cair,
        // é ali que a queda aparece, não aqui.
        if ($team === null) {
            return $next($request);
        }

        $plan = TeamBilling::for($team)->plan();

        // O plano primeiro: quem já tem o recurso pelo que paga nem chega a
        // gravar uma resposta no Pennant.
        if (! $plan->hasFeature($feature) && ! Feature::for($team)->active($feature)) {
            throw PlanFeatureException::required($feature);
        }

        return $next($request);
    }
}
