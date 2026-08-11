<?php

declare(strict_types=1);

namespace Modules\Consultas\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consultas\Enums\ProviderOutcome;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Models\ConsultaExecution;

/**
 * @extends Factory<ConsultaExecution>
 */
class ConsultaExecutionFactory extends Factory
{
    protected $model = ConsultaExecution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // O time vem explícito para servir aos testes que montam dois times.
            'team_id' => Team::factory(),
            'consulta_id' => Consulta::factory(),
            'user_id' => null,
            'outcome' => ProviderOutcome::Success,
            'duration_ms' => $this->faker->numberBetween(50, 2000),
            'input_masked' => ['cpf' => '123******01'],
            'result' => ['nome' => 'Fulano'],
            'failure_reason' => null,
        ];
    }
}
