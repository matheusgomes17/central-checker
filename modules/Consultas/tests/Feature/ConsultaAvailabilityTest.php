<?php

declare(strict_types=1);

use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Support\CircuitBreaker;
use Modules\Consultas\Support\ConsultaAvailability;

beforeEach(function (): void {
    $this->availability = new ConsultaAvailability($breaker = new CircuitBreaker);
    $this->breaker = $breaker;
});

it('consulta ativa com circuito fechado está disponível', function (): void {
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    expect($this->availability->available($consulta))->toBeTrue();
});

it('consulta em manutenção manual não está disponível', function (): void {
    $consulta = Consulta::factory()->inMaintenance()->create();

    expect($this->availability->available($consulta))->toBeFalse();
});

it('circuito aberto derruba a disponibilidade mesmo com status ativo', function (): void {
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $this->breaker->recordFailure('fake');
    }

    expect($this->availability->available($consulta))->toBeFalse();
});
