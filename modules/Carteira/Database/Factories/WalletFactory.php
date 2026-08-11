<?php

declare(strict_types=1);

namespace Modules\Carteira\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Carteira\Models\Wallet;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'balance_cents' => 0,
        ];
    }

    public function withBalance(int $cents): self
    {
        return $this->state(['balance_cents' => $cents]);
    }
}
