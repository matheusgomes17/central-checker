<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in for a real tenant owned domain model.
 *
 * The boilerplate ships with no domain models of its own, so the tenancy rules
 * are exercised against this one. Testing the trait through a fixture rather
 * than through Team or User is deliberate: those are infrastructure and are
 * never tenant scoped, so they could not prove anything about the trait.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 */
#[Fillable(['name', 'team_id'])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'tenant_resources')]
class TenantResource extends Model
{
    use BelongsToTenant;
}
