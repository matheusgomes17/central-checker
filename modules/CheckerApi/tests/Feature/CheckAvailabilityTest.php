<?php

declare(strict_types=1);

use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Support\CheckAvailability;
use Modules\CheckerApi\Support\CircuitBreaker;

it('check ativo com circuito fechado está disponível', function (): void {
    $breaker = new CircuitBreaker;
    $availability = new CheckAvailability($breaker);

    $check = Check::factory()->create(['provider_key' => 'fake']);

    expect($availability->available($check))->toBeTrue();
});

it('check em manutenção manual não está disponível', function (): void {
    $breaker = new CircuitBreaker;
    $availability = new CheckAvailability($breaker);

    $check = Check::factory()->inMaintenance()->create();

    expect($availability->available($check))->toBeFalse();
});

it('circuito aberto derruba a disponibilidade mesmo com status ativo', function (): void {
    $breaker = new CircuitBreaker;
    $availability = new CheckAvailability($breaker);

    $check = Check::factory()->create(['provider_key' => 'fake']);

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $breaker->recordFailure('fake');
    }

    expect($availability->available($check))->toBeFalse();
});
