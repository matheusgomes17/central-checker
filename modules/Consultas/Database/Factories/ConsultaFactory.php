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
        $name = $this->faker->unique()->words(2, true);

        return [
            'slug' => str($name)->slug()->value(),
            'name' => ucfirst((string) $name),
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
