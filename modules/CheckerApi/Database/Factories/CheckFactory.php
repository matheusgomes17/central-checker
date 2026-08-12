<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CheckerApi\Enums\CheckStatus;
use Modules\CheckerApi\Models\Check;

/**
 * @extends Factory<Check>
 */
class CheckFactory extends Factory
{
    protected $model = Check::class;

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
            'price_cents' => 0,
            'status' => CheckStatus::Active,
            'timeout_seconds' => 30,
        ];
    }

    public function inMaintenance(): self
    {
        return $this->state(['status' => CheckStatus::Maintenance]);
    }

    public function priced(int $cents): self
    {
        return $this->state(['price_cents' => $cents]);
    }
}
