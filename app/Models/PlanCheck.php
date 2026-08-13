<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CheckerApi\Models\Check;

/**
 * Links a plan to a check with a quota (allowance) — how many times the plan
 * includes that check before billing charges per use.
 *
 * Global catalog config, like `Plan` and `Check`: the operator defines which
 * checks each plan includes, and every team reads the same table. See
 * `App\Support\TenantManager::UNSCOPED_MODELS` for the allowlist entry and
 * its justification.
 *
 * @property int $id
 * @property string $plan
 * @property int $check_id
 * @property int|null $quota
 */
#[Fillable(['plan', 'check_id', 'quota'])]
class PlanCheck extends Model
{
    /** @use HasFactory<PlanCheckFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Check, $this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function isUnlimited(): bool
    {
        return $this->quota === null;
    }
}
