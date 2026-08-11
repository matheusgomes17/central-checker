<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Pagamentos\Enums\PaymentPurpose;
use Modules\Pagamentos\Enums\PaymentStatus;
use Modules\Pagamentos\Models\PaymentIntent;

it('cria um payment intent escopado e cifra os casts de enum', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    $intent = PaymentIntent::factory()->create([
        'team_id' => $team->id,
        'reference' => 'RECHARGE-0000000001',
        'amount_cents' => 5000,
    ]);

    $fresh = $intent->fresh();

    expect($fresh->team_id)->toBe($team->id)
        ->and($fresh->reference)->toBe('RECHARGE-0000000001')
        ->and($fresh->amount_cents)->toBe(5000)
        ->and($fresh->status)->toBe(PaymentStatus::Pending)
        ->and($fresh->purpose)->toBe(PaymentPurpose::Recharge)
        ->and($fresh->method)->toBe('pix');
});

it('é escopado por time — um time não vê o payment intent do outro', function (): void {
    $tenants = resolve(TenantManager::class);

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $tenants->set($teamA);
    PaymentIntent::factory()->create(['team_id' => $teamA->id]);

    $tenants->set($teamB);

    expect(PaymentIntent::query()->count())->toBe(0);
});
