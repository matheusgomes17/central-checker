<?php

declare(strict_types=1);

use Modules\CheckerApi\Support\CheckInput;
use Modules\CheckerApi\Support\CircuitBreaker;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderResult;
use Modules\CheckerApi\Support\ResilientProvider;

function noSleep(): Closure
{
    return static fn (int $attempt): null => null;
}

function input(): CheckInput
{
    return CheckInput::fromArray(['cpf' => '12345678901']);
}

it('devolve sucesso sem repetir', function (): void {
    $inner = FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']));
    $resilient = new ResilientProvider($inner, new CircuitBreaker, backoff: noSleep());

    expect($resilient->execute(input())->isSuccess())->toBeTrue()
        ->and($inner->calls())->toBe(1);
});

it('não repete quando o desfecho é nada encontrado', function (): void {
    $inner = FakeProvider::returning(ProviderResult::noData());
    $resilient = new ResilientProvider($inner, new CircuitBreaker, backoff: noSleep());

    expect($resilient->execute(input())->isNoData())->toBeTrue()
        ->and($inner->calls())->toBe(1);
});

it('repete em falha até o limite', function (): void {
    $inner = FakeProvider::returning(ProviderResult::failed('timeout'));
    $resilient = new ResilientProvider($inner, new CircuitBreaker, maxTries: 2, backoff: noSleep());

    expect($resilient->execute(input())->isFailed())->toBeTrue()
        ->and($inner->calls())->toBe(2);
});

it('para de repetir assim que um sucesso aparece', function (): void {
    $inner = FakeProvider::returning(
        ProviderResult::failed('timeout'),
        ProviderResult::success(['nome' => 'Fulano']),
    );
    $resilient = new ResilientProvider($inner, new CircuitBreaker, maxTries: 3, backoff: noSleep());

    expect($resilient->execute(input())->isSuccess())->toBeTrue()
        ->and($inner->calls())->toBe(2);
});

it('converte exceção do provider em falha', function (): void {
    $inner = FakeProvider::throwing(new RuntimeException('conexão recusada'));
    $resilient = new ResilientProvider($inner, new CircuitBreaker, maxTries: 1, backoff: noSleep());

    $result = $resilient->execute(input());

    expect($result->isFailed())->toBeTrue()
        ->and($result->reason())->toBe('conexão recusada');
});

it('não chama o inner com o circuito aberto', function (): void {
    $breaker = new CircuitBreaker;
    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $breaker->recordFailure('fake');
    }

    $inner = FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']));
    $resilient = new ResilientProvider($inner, $breaker, backoff: noSleep());

    expect($resilient->execute(input())->isFailed())->toBeTrue()
        ->and($inner->calls())->toBe(0);
});

it('fecha o breaker ao voltar a ter sucesso', function (): void {
    $breaker = new CircuitBreaker;
    $breaker->recordFailure('fake');

    $inner = FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']));
    $resilient = new ResilientProvider($inner, $breaker, backoff: noSleep());

    $resilient->execute(input());

    // Uma falha a menos do que o limite: se o sucesso não tivesse zerado, a
    // próxima sequência de falhas abriria antes.
    expect($breaker->isOpen('fake'))->toBeFalse();
});
