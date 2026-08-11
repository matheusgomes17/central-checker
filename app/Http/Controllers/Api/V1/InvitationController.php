<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Teams\InviteTeamMember;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreInvitationRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Invitations for the team the token speaks for.
 *
 * Nothing here returns the invitation code. It is the credential that gets
 * someone into the team, so a list of invitations must not be a list of keys —
 * and an API list is exactly the sort of thing that ends up in a log.
 */
class InvitationController extends Controller
{
    /**
     * List the team's invitations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $team = $this->team();

        Gate::authorize('inviteMember', $team);

        $query = QueryBuilder::for($team->invitations())
            ->allowedFilters(
                AllowedFilter::exact('email'),
                AllowedFilter::exact('role'),
            )
            ->allowedSorts('email', 'created_at', 'expires_at')
            ->defaultSort('-created_at');

        return InvitationResource::collection($query->cursorPaginate(
            perPage: min(100, max(1, $request->integer('per_page', 25))),
        ));
    }

    /**
     * Invite someone to the team.
     */
    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $team = $this->team();
        $user = $request->user();

        abort_if(! $user instanceof User, 401);

        Gate::authorize('inviteMember', $team);

        $invitation = resolve(InviteTeamMember::class)->handle(
            $team,
            $request->string('email')->toString(),
            TeamRole::from($request->string('role')->toString()),
            $user,
        );

        return InvitationResource::make($invitation)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Cancel an invitation.
     */
    public function destroy(Request $request, TeamInvitation $invitation): Response
    {
        $team = $this->team();

        Gate::authorize('cancelInvitation', $team);

        // Buscado através do time: um id de outro time devolve 404 em vez de
        // cancelar o convite dele.
        $found = $team->invitations()->whereKey($invitation->getKey())->first();

        abort_if(! $found instanceof TeamInvitation, 404);

        $found->delete();

        return response()->noContent();
    }

    private function team(): Team
    {
        $team = resolve(TenantManager::class)->current();

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
