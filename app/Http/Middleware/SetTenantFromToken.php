<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Team;
use App\Support\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the token decide which team the request is about.
 *
 * On the web the tenant comes from the session; over the API there is no
 * session, and letting it come from the user's "current team" would mean an
 * API call answering differently depending on what its owner last clicked in
 * a browser. The token names the team, and nothing else does.
 *
 * A token whose team no longer exists, or whose owner is no longer a member of
 * it, is refused rather than falling back to something. Falling back is how a
 * revoked membership keeps its access.
 */
class SetTenantFromToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof ApiToken) {
            return $next($request);
        }

        $team = $token->team;

        abort_if(! $team instanceof Team, Response::HTTP_FORBIDDEN, __('This token has no team.'));

        // O usuário existe: chegar aqui exigiu um token autenticado, e o
        // token pertence a alguém.
        abort_unless(
            $request->user()->belongsToTeam($team),
            Response::HTTP_FORBIDDEN,
            __('This token belongs to a team you are no longer a member of.'),
        );

        resolve(TenantManager::class)->set($team);

        return $next($request);
    }
}
