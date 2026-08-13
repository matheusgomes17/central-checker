<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetTeamUrlDefaults
{
    /**
     * Set the default URL parameters for team-based routes.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Só o default de `team` continua útil: settings/teams/{team} é a
        // única rota que ainda tem esse param na URL. As rotas do produto não
        // levam mais time nenhum no caminho, então não há mais `current_team`
        // para preencher.
        if ($currentTeam = $request->user()?->currentTeam) {
            URL::defaults([
                'team' => $currentTeam->slug,
            ]);
        }

        return $next($request);
    }
}
