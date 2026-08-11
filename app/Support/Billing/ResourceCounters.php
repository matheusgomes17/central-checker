<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Team;
use InvalidArgumentException;

/**
 * Maps a metered resource to whoever knows how to count it.
 *
 * Answering zero for an unknown resource is what the previous implementation
 * did, and it is the worst of the options: the plan reports room that nobody
 * is measuring, and nothing ever says so.
 */
class ResourceCounters
{
    /**
     * @var array<string, CountsPlanResource>
     */
    private array $counters = [];

    public function register(string $resource, CountsPlanResource $counter): void
    {
        $this->counters[$resource] = $counter;
    }

    public function countFor(string $resource, Team $team): int
    {
        $counter = $this->counters[$resource] ?? null;

        if (! $counter instanceof CountsPlanResource) {
            throw new InvalidArgumentException(
                "Nenhum contador registrado para o recurso [{$resource}]. Registre um antes de limitá-lo em config/billing.php.",
            );
        }

        return $counter->count($team);
    }

    /**
     * @return array<int, string>
     */
    public function registered(): array
    {
        return array_keys($this->counters);
    }
}
