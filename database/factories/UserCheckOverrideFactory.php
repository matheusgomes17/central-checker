<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use App\Models\UserCheckOverride;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CheckerApi\Models\Check;

/**
 * @extends Factory<UserCheckOverride>
 */
class UserCheckOverrideFactory extends Factory
{
    protected $model = UserCheckOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'check_id' => Check::factory(),
            'blocked' => false,
            'granted' => false,
            'price_cents' => null,
        ];
    }

    public function blocked(): self
    {
        return $this->state(['blocked' => true]);
    }

    public function granted(): self
    {
        return $this->state(['granted' => true]);
    }

    public function priced(int $cents): self
    {
        return $this->state(['price_cents' => $cents]);
    }
}
