<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlanSubscription;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanSubscription>
 */
class PlanSubscriptionFactory extends Factory
{
    protected $model = PlanSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'plan' => 'pro',
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
            'canceled_at' => null,
        ];
    }

    public function active(): self
    {
        return $this->state([
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
            'canceled_at' => null,
        ]);
    }

    public function expired(): self
    {
        return $this->state([
            'current_period_start' => now()->subDays(60),
            'current_period_end' => now()->subDays(30),
            'canceled_at' => null,
        ]);
    }

    public function canceling(): self
    {
        return $this->state([
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
            'canceled_at' => now(),
        ]);
    }
}
