<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MemberResource;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class MemberController extends Controller
{
    /**
     * List the members of the current team.
     *
     * Paginated by cursor rather than by page number: a member removed while
     * someone is paging shifts every subsequent page, and page-number
     * pagination silently skips a row when that happens.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $team = $this->team();

        // A relação, não ->getQuery(): numa belongsToMany o join do pivot é
        // aplicado na hora de executar, e pegar o builder cru lista todos os
        // usuários da base em vez dos membros do time.
        $query = QueryBuilder::for($team->members())
            // Variádico, não array: o pacote declara ...$filters, e passar um
            // array faz o primeiro argumento ser o array inteiro.
            ->allowedFilters(
                AllowedFilter::partial('name'),
                AllowedFilter::exact('email'),
            )
            ->allowedSorts('name', 'email', 'created_at')
            ->defaultSort('name');

        return MemberResource::collection($query->cursorPaginate(
            perPage: min(100, max(1, (int) $request->integer('per_page', 25))),
        ));
    }

    public function show(Request $request, User $member): MemberResource
    {
        $team = $this->team();

        // A busca é feita através do time, não pelo id solto: assim um id de
        // outro time devolve 404 em vez de devolver a pessoa.
        $found = $team->members()->whereKey($member->getKey())->first();

        abort_if(! $found instanceof User, 404);

        return new MemberResource($found);
    }

    /**
     * Remove someone from the current team.
     */
    public function destroy(Request $request, User $member): Response
    {
        $team = $this->team();

        Gate::authorize('removeMember', $team);

        abort_unless($team->members()->whereKey($member->getKey())->exists(), 404);

        // O model, e não um delete de query: evento de model só dispara quando
        // um model é apagado, e o webhook de saída vem daí.
        $team->memberships()->where('user_id', $member->getKey())->firstOrFail()->delete();
        $member->removeTeamRoles($team);

        return response()->noContent();
    }

    private function team(): Team
    {
        $team = resolve(TenantManager::class)->current();

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
