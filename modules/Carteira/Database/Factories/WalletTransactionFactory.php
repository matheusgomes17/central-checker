<?php

declare(strict_types=1);

namespace Modules\Carteira\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletTransaction;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'wallet_id' => Wallet::factory(),
            'type' => WalletTransactionType::Recharge,
            'amount_cents' => 1000,
            'balance_after_cents' => 1000,
            'description' => 'Recarga de teste',
            'reference_type' => null,
            'reference_id' => null,
        ];
    }
}
