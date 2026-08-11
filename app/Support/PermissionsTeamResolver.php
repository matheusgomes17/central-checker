<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver as PermissionsTeamResolverContract;

/**
 * Points the permission package at the tenant the application already resolved.
 *
 * The package ships its own resolver holding a team id of its own. Keeping it
 * would mean two answers to "which team are we in" — and the day they disagree
 * is the day a permission check passes against another team's roles. This one
 * has no state: it forwards to the TenantManager.
 *
 * The manager is pulled from the container on each call rather than injected,
 * because the package builds this class with `new` and never sees the
 * container.
 */
class PermissionsTeamResolver implements PermissionsTeamResolverContract
{
    public function getPermissionsTeamId(): int|string|null
    {
        return $this->tenants()->id();
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Team) {
            $this->tenants()->set($id);

            return;
        }

        if ($id === null) {
            $this->tenants()->set(null);

            return;
        }

        // O pacote também aceita id cru — resolvemos para o model porque o
        // TenantManager guarda o tenant, não o identificador.
        $this->tenants()->set(Team::query()->whereKey($id)->first());
    }

    private function tenants(): TenantManager
    {
        return resolve(TenantManager::class);
    }
}
