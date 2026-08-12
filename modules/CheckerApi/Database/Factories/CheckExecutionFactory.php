<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;

/**
 * @extends Factory<CheckExecution>
 */
class CheckExecutionFactory extends Factory
{
    protected $model = CheckExecution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // O time vem explícito para servir aos testes que montam dois times.
            'team_id' => Team::factory(),
            'check_id' => Check::factory(),
            'user_id' => null,
            'outcome' => ProviderOutcome::Success,
            'duration_ms' => $this->faker->numberBetween(50, 2000),
            'input_masked' => ['cpf' => '123******01'],
            'result' => ['nome' => 'Fulano'],
            'failure_reason' => null,
        ];
    }
}
