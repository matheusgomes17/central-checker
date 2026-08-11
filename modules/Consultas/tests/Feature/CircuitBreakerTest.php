<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Consultas\Support\CircuitBreaker;

beforeEach(function (): void {
    $this->breaker = new CircuitBreaker;
});

it('começa fechado', function (): void {
    expect($this->breaker->isOpen('serasa'))->toBeFalse();
});

it('abre depois do limite de falhas seguidas', function (): void {
    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $this->breaker->recordFailure('serasa');
    }

    expect($this->breaker->isOpen('serasa'))->toBeTrue();
});

it('sucesso no meio zera o contador', function (): void {
    $this->breaker->recordFailure('serasa');
    $this->breaker->recordFailure('serasa');
    $this->breaker->recordSuccess('serasa');
    $this->breaker->recordFailure('serasa');

    expect($this->breaker->isOpen('serasa'))->toBeFalse();
});

it('fecha de novo passado o resfriamento', function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $this->breaker->recordFailure('serasa');
    }
    expect($this->breaker->isOpen('serasa'))->toBeTrue();

    Carbon::setTestNow(Carbon::now()->addSeconds(CircuitBreaker::COOLDOWN_SECONDS + 1));
    expect($this->breaker->isOpen('serasa'))->toBeFalse();
});

it('separa o estado por chave de provider', function (): void {
    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $this->breaker->recordFailure('serasa');
    }

    expect($this->breaker->isOpen('serasa'))->toBeTrue()
        ->and($this->breaker->isOpen('receita'))->toBeFalse();
});
