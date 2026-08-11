<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Exceptions\TenantIsMissingException;
use App\Models\Scopes\TenantScope;
use App\Models\Team;
use App\Support\TenantManager;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confines a model to a single team.
 *
 * Two halves, and both are needed. The global scope keeps reads inside the
 * current tenant; filling team_id on create keeps writes there. Without the
 * second half a row can be written with no owner, and a row with no owner is
 * invisible to every tenant afterwards — a silent data loss rather than a
 * loud failure.
 *
 * Creating a row with no tenant in scope throws instead of writing null. The
 * caller either knows which team it means or has a bug.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(resolve(TenantScope::class));

        static::creating(function (Model $model): void {
            if ($model->getAttribute('team_id') !== null) {
                return;
            }

            $tenants = resolve(TenantManager::class);

            if (! $tenants->has()) {
                throw TenantIsMissingException::forModel($model::class);
            }

            $model->setAttribute('team_id', $tenants->id());
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Query this model across every tenant.
     *
     * Reads better at the call site than reaching for the manager, and keeps
     * the deliberate crossing visible in the query itself.
     *
     * @return Builder<static>
     */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Run a callback with tenant scoping suspended.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutTenancy(Closure $callback): mixed
    {
        return resolve(TenantManager::class)->withoutTenancy($callback);
    }
}
