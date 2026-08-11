<?php

declare(strict_types=1);

namespace Modules\Consultas\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consultas\Enums\ConsultaStatus;
use Modules\Consultas\Models\Consulta;

/**
 * @extends Factory<Consulta>
 */
class ConsultaFactory extends Factory
{
    protected $model = Consulta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words() is typed array|string upstream (it flips on $asText), então
        // montamos o nome com duas chamadas a word() — cada uma tipada string —
        // para não carregar essa união adiante.
        $name = $this->faker->unique()->word().' '.$this->faker->unique()->word();

        return [
            'slug' => str($name)->slug()->value(),
            'name' => ucfirst($name),
            'description' => $this->faker->sentence(),
            'category' => 'cadastral',
            'provider_key' => 'fake',
            'status' => ConsultaStatus::Active,
            'timeout_seconds' => 30,
        ];
    }

    public function inMaintenance(): self
    {
        return $this->state(['status' => ConsultaStatus::Maintenance]);
    }
}
