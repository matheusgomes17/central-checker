<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\CheckerApi\Database\Factories\CheckExecutionFactory;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Override;

/**
 * The record of a single check run, for history and provider reporting.
 *
 * Scoped by team: an execution belongs to whoever ran it. The raw result is
 * encrypted at rest and the input is stored masked — never the raw PII — so a
 * leaked backup or a careless query does not expose a third party's data.
 *
 * @property int $id
 * @property int $team_id
 * @property int $check_id
 * @property int|null $user_id
 * @property ProviderOutcome $outcome
 * @property bool $consumed_franquia
 * @property int $duration_ms
 * @property array<string, string> $input_masked
 * @property array<string, mixed>|null $result
 * @property string|null $failure_reason
 */
#[Fillable(['check_id', 'user_id', 'outcome', 'consumed_franquia', 'duration_ms', 'input_masked', 'result', 'failure_reason'])]
class CheckExecution extends Model
{
    /** @use HasFactory<CheckExecutionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'outcome' => ProviderOutcome::class,
            'consumed_franquia' => 'boolean',
            'input_masked' => 'array',
            'result' => 'encrypted:array',
        ];
    }
}
