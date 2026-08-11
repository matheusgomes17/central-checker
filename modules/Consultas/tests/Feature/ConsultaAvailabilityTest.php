<?php

declare(strict_types=1);

use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Support\CircuitBreaker;
use Modules\Consultas\Support\ConsultaAvailability;

it('consulta ativa com circuito fechado está disponível', function (): void {
    $breaker = new CircuitBreaker;
    $availability = new ConsultaAvailability($breaker);

    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    expect($availability->available($consulta))->toBeTrue();
});

it('consulta em manutenção manual não está disponível', function (): void {
    $breaker = new CircuitBreaker;
    $availability = new ConsultaAvailability($breaker);

    $consulta = Consulta::factory()->inMaintenance()->create();

    expect($availability->available($consulta))->toBeFalse();
});

it('circuito aberto derruba a disponibilidade mesmo com status ativo', function (): void {
    $breaker = new CircuitBreaker;
    $availability = new ConsultaAvailability($breaker);

    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $breaker->recordFailure('fake');
    }

    expect($availability->available($consulta))->toBeFalse();
});
