<?php

declare(strict_types=1);

namespace Modules\Carteira\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;

/**
 * @extends Factory<WalletHold>
 */
class WalletHoldFactory extends Factory
{
    protected $model = WalletHold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'wallet_id' => Wallet::factory(),
            'amount_cents' => 350,
            'status' => WalletHoldStatus::Active,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
