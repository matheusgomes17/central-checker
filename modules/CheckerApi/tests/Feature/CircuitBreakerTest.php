<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Modules\CheckerApi\Support\CircuitBreaker;

it('começa fechado', function (): void {
    $breaker = new CircuitBreaker;

    expect($breaker->isOpen('serasa'))->toBeFalse();
});

it('abre depois do limite de falhas seguidas', function (): void {
    $breaker = new CircuitBreaker;

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $breaker->recordFailure('serasa');
    }

    expect($breaker->isOpen('serasa'))->toBeTrue();
});

it('sucesso no meio zera o contador', function (): void {
    $breaker = new CircuitBreaker;

    $breaker->recordFailure('serasa');
    $breaker->recordFailure('serasa');
    $breaker->recordSuccess('serasa');
    $breaker->recordFailure('serasa');

    expect($breaker->isOpen('serasa'))->toBeFalse();
});

it('fecha de novo passado o resfriamento', function (): void {
    $breaker = new CircuitBreaker;

    Date::setTestNow('2026-08-10 12:00:00');

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $breaker->recordFailure('serasa');
    }
    expect($breaker->isOpen('serasa'))->toBeTrue();

    Date::setTestNow(Date::now()->addSeconds(CircuitBreaker::COOLDOWN_SECONDS + 1));
    expect($breaker->isOpen('serasa'))->toBeFalse();
});

it('separa o estado por chave de provider', function (): void {
    $breaker = new CircuitBreaker;

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $breaker->recordFailure('serasa');
    }

    expect($breaker->isOpen('serasa'))->toBeTrue()
        ->and($breaker->isOpen('receita'))->toBeFalse();
});
