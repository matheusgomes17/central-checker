<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Data\TeamPermissions;
use App\Data\UserTeam;
use App\Enums\SystemRole;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Support\TenantManager;
use Closure;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

trait HasTeams
{
    /**
     * Get all of the teams the user belongs to.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members', 'user_id', 'team_id')
            ->withTimestamps();
    }

    /**
     * Get all of the teams the user owns.
     *
     * @return HasManyThrough<Team, Membership, $this>
     */
    public function ownedTeams(): HasManyThrough
    {
        return $this->hasManyThrough(
            Team::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'team_id',
        )->whereIn('teams.id', $this->teamIdsWithRole(TeamRole::Owner));
    }

    /**
     * Ids of the teams where the user holds a given role.
     *
     * Goes to the assignment table directly: the role now lives there, and a
     * relation cannot join across the package's team scoping.
     *
     * @return array<int, int>
     */
    public function teamIdsWithRole(TeamRole $role): array
    {
        /** @var array<int, int> $ids */
        $ids = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->where('roles.name', $role->value)
            ->pluck('model_has_roles.team_id')
            ->all();

        return $ids;
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current team.
     *
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Get the user's personal team.
     */
    public function personalTeam(): ?Team
    {
        return $this->teams()
            ->where('is_personal', true)
            ->first();
    }

    /**
     * Switch to the given team.
     */
    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->update(['current_team_id' => $team->id]);
        $this->setRelation('currentTeam', $team);

        // O TenantManager guarda a resposta pelo resto da requisição. Sem
        // invalidar aqui, tudo que rodar depois da troca continua escopado no
        // time anterior.
        resolve(TenantManager::class)->set($team);

        URL::defaults(['current_team' => $team->slug]);

        return true;
    }

    /**
     * Determine if the user belongs to the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->where('teams.id', $team->id)->exists();
    }

    /**
     * Determine if the given team is the user's current team.
     */
    public function isCurrentTeam(Team $team): bool
    {
        return $this->current_team_id === $team->id;
    }

    /**
     * Determine if the user is the owner of the given team.
     */
    public function ownsTeam(Team $team): bool
    {
        return $this->teamRole($team) === TeamRole::Owner;
    }

    /**
     * Get the user's role on the given team.
     */
    public function teamRole(Team $team): ?TeamRole
    {
        return $this->withinTeam($team, function () use ($team): ?TeamRole {
            if (! $this->belongsToTeam($team)) {
                return null;
            }

            // getRoleNames() em vez de ->roles->first()->name: é a API do
            // pacote e devolve nomes, não models — o relacionamento é tipado
            // como Model genérico e não conhece a propriedade.
            $name = $this->getRoleNames()->first();

            return is_string($name) ? TeamRole::tryFrom($name) : null;
        });
    }

    /**
     * Assign the user's role inside a team, replacing whatever it was.
     *
     * The only place that writes a role. Everything else goes through here so
     * that how roles are stored stays one decision instead of a dozen.
     */
    public function assignTeamRole(Team $team, TeamRole $role): void
    {
        $this->withinTeam($team, function () use ($role): void {
            // syncRoles e não assignRole: um membro tem exatamente um papel
            // por time, e acrescentar deixaria o anterior valendo junto.
            $this->syncRoles([$role->value]);
        });
    }

    /**
     * Drop every role the user holds inside a team.
     */
    public function removeTeamRoles(Team $team): void
    {
        $this->withinTeam($team, function (): void {
            $this->syncRoles([]);
        });
    }

    /**
     * Run a callback with the permission package pointed at a given team.
     *
     * The package answers "which roles" against whatever team is current, so
     * asking about another team means moving there and back. The loaded roles
     * relation is dropped on both ends because it caches the answer for the
     * team it was loaded under, and a stale cache here is a wrong permission.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withinTeam(Team $team, Closure $callback): mixed
    {
        return resolve(TenantManager::class)->forTenant($team, function () use ($callback): mixed {
            $this->unsetRelation('roles');

            try {
                return $callback();
            } finally {
                $this->unsetRelation('roles');
            }
        });
    }

    /**
     * Get the user's teams as a collection of UserTeam objects.
     *
     * @return Collection<int, UserTeam>
     */
    public function toUserTeams(bool $includeCurrent = false): Collection
    {
        return $this->teams()
            ->get()
            ->map(fn (Team $team) => ! $includeCurrent && $this->isCurrentTeam($team) ? null : $this->toUserTeam($team))
            ->filter()
            ->values();
    }

    /**
     * Get the user's team as a UserTeam object.
     */
    public function toUserTeam(Team $team): UserTeam
    {
        $role = $this->teamRole($team);

        return new UserTeam(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            isPersonal: $team->is_personal,
            role: $role?->value,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentTeam($team),
        );
    }

    /**
     * Get the standard permissions for a team as a TeamPermissions object.
     */
    public function toTeamPermissions(Team $team): TeamPermissions
    {
        $role = $this->teamRole($team);

        return new TeamPermissions(
            canUpdateTeam: $role?->hasPermission(TeamPermission::UpdateTeam) ?? false,
            canDeleteTeam: $role?->hasPermission(TeamPermission::DeleteTeam) ?? false,
            canAddMember: $role?->hasPermission(TeamPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(TeamPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(TeamPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(TeamPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(TeamPermission::CancelInvitation) ?? false,
        );
    }

    public function fallbackTeam(?Team $excluding = null): ?Team
    {
        return $this->teams()
            ->when($excluding, fn ($query, Team $team) => $query->where('teams.id', '!=', $team->id))
            ->orderByRaw('LOWER(teams.name)')
            ->first();
    }

    /**
     * Whether the user holds a platform-wide role.
     *
     * Kept out of the permission tables on purpose: their team mode puts the
     * team id inside the assignment's primary key, so a grant belonging to no
     * team has nowhere to live. The enum stays as the vocabulary; the column
     * is the storage.
     */
    public function hasSystemRole(SystemRole $role): bool
    {
        return match ($role) {
            SystemRole::SuperAdmin => (bool) $this->getAttribute('is_super_admin'),
        };
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasSystemRole(SystemRole::SuperAdmin);
    }

    /**
     * Grant or revoke a platform-wide role.
     */
    public function setSystemRole(SystemRole $role, bool $granted = true): void
    {
        match ($role) {
            SystemRole::SuperAdmin => $this->forceFill(['is_super_admin' => $granted])->save(),
        };
    }

    /**
     * Determine if the user has the given permission on the team.
     */
    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        return $this->teamRole($team)?->hasPermission($permission) ?? false;
    }
}
