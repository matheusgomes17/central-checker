<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TeamResource;
use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The team the token speaks for.
 *
 * There is no index: a token names one team, and listing "the teams" over an
 * API scoped to one of them would either lie or leak.
 */
class TeamController extends Controller
{
    /**
     * Show the current team.
     */
    public function show(Request $request): JsonResponse
    {
        $team = resolve(TenantManager::class)->current();

        abort_if(! $team instanceof Team, 404);

        // Status explícito: o Laravel devolve 201 quando o model foi criado no
        // mesmo processo, e isso faria o código de uma leitura depender do que
        // aconteceu antes dela.
        return TeamResource::make($team->loadCount('memberships'))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
