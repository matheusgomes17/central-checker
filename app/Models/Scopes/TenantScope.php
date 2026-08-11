<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Confines every query on a tenant aware model to the current tenant.
 *
 * The important decision here is what happens when there is no tenant. Letting
 * the query through unscoped would mean any bug that loses the tenant — a job
 * that forgets to set it, a middleware that does not run — quietly returns
 * every team's rows to whoever asked. So the scope fails closed: no tenant,
 * no rows.
 *
 * That is deliberately inconvenient for legitimate cross-tenant work, which
 * is why TenantManager::withoutTenancy() exists. Crossing tenants should be
 * something you write on purpose, not something you get by forgetting.
 */
/**
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    public const string IMPOSSIBLE = '0 = 1';

    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($this->tenants->isSuspended()) {
            return;
        }

        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            $builder->whereRaw(self::IMPOSSIBLE);

            return;
        }

        $builder->where($model->qualifyColumn('team_id'), $tenantId);
    }
}
