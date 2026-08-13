<?php

declare(strict_types=1);

namespace Modules\Payments\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payments\Enums\PaymentPurpose;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\PaymentIntent;

/**
 * @extends Factory<PaymentIntent>
 */
class PaymentIntentFactory extends Factory
{
    protected $model = PaymentIntent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // O time vem explícito para servir aos testes que montam dois times.
            'team_id' => Team::factory(),
            'reference' => 'RECHARGE-'.$this->faker->unique()->numerify('##########'),
            'external_id' => null,
            'amount_cents' => 1000,
            'status' => PaymentStatus::Pending,
            'method' => 'pix',
            'purpose' => PaymentPurpose::Recharge,
            'copy_paste' => null,
            'qr_code' => null,
            'expires_at' => now()->addHour(),
            'paid_at' => null,
        ];
    }

    public function paid(): self
    {
        return $this->state([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function withExternalId(string $id): self
    {
        return $this->state(['external_id' => $id]);
    }
}
