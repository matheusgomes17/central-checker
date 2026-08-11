<?php

declare(strict_types=1);

use Modules\Consultas\Enums\ProviderOutcome;
use Modules\Consultas\Support\ProviderResult;

it('carrega dado no sucesso', function (): void {
    $result = ProviderResult::success(['nome' => 'Fulano']);

    expect($result->outcome())->toBe(ProviderOutcome::Success)
        ->and($result->isSuccess())->toBeTrue()
        ->and($result->data())->toBe(['nome' => 'Fulano'])
        ->and($result->reason())->toBeNull();
});

it('não encontrar registro é um desfecho, não erro', function (): void {
    $result = ProviderResult::noData();

    expect($result->outcome())->toBe(ProviderOutcome::NoData)
        ->and($result->isNoData())->toBeTrue()
        ->and($result->isFailed())->toBeFalse()
        ->and($result->data())->toBe([]);
});

it('falha carrega o motivo', function (): void {
    $result = ProviderResult::failed('timeout');

    expect($result->outcome())->toBe(ProviderOutcome::Failed)
        ->and($result->isFailed())->toBeTrue()
        ->and($result->reason())->toBe('timeout');
});
