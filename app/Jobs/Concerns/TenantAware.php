<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Team;
use App\Support\TenantManager;

/**
 * Carries the tenant across the queue boundary.
 *
 * A queued job runs with no request, no session and no signed-in user, so the
 * TenantManager has nothing to resolve from — and a tenant-scoped model
 * queried inside a job would return nothing at all, because the scope fails
 * closed. Whatever team the job is about has to travel with it.
 *
 * Tagging matters for the same reason it matters in a log: a queue with ten
 * thousand jobs is unreadable until you can ask which ones belong to one
 * customer.
 */
trait TenantAware
{
    public ?int $tenantId = null;

    /**
     * Remember the team this job is about.
     *
     * Called while the job is being constructed, when there still is a tenant
     * to read. Doing it at run time would be too late.
     */
    public function forTenant(?Team $team = null): static
    {
        // Escrito em duas saídas de propósito: a forma condensada com ?-> à
        // esquerda de ?? é o que o Rector prefere e o que o PHPStan reprova,
        // e ficar alternando entre as duas não ajuda ninguém.
        if ($team instanceof Team) {
            $this->tenantId = $team->id;

            return $this;
        }

        $this->tenantId = resolve(TenantManager::class)->id();

        return $this;
    }

    /**
     * Put the tenant back before the job body runs.
     */
    public function restoreTenant(): void
    {
        if ($this->tenantId === null) {
            return;
        }

        resolve(TenantManager::class)->set(Team::query()->whereKey($this->tenantId)->first());
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return $this->tenantId === null
            ? [class_basename($this)]
            : [class_basename($this), 'team:'.$this->tenantId];
    }
}
