<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlanCheck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CheckerApi\Models\Check;

/**
 * @extends Factory<PlanCheck>
 */
class PlanCheckFactory extends Factory
{
    protected $model = PlanCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan' => 'pro',
            'check_id' => Check::factory(),
            'quota' => 100,
        ];
    }

    public function unlimited(): self
    {
        return $this->state(['quota' => null]);
    }
}
