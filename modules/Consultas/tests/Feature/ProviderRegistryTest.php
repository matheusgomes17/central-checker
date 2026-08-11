<?php

declare(strict_types=1);

use Modules\Consultas\Exceptions\ProviderNotRegisteredException;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\FakeProvider;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ProviderResult;

it('registra e resolve um provider pela chave', function (): void {
    $registry = new ProviderRegistry;
    $provider = FakeProvider::returning(ProviderResult::noData())->withKey('serasa');

    $registry->register($provider);

    expect($registry->has('serasa'))->toBeTrue()
        ->and($registry->resolve('serasa'))->toBe($provider)
        ->and($registry->keys())->toBe(['serasa']);
});

it('recusa resolver provider desconhecido', function (): void {
    $registry = new ProviderRegistry;

    $registry->resolve('inexistente');
})->throws(ProviderNotRegisteredException::class);

it('o fake devolve resultados em ordem e conta chamadas', function (): void {
    $provider = FakeProvider::returning(
        ProviderResult::failed('timeout'),
        ProviderResult::success(['nome' => 'Fulano']),
    );

    $input = ConsultaInput::fromArray(['cpf' => '12345678901']);

    expect($provider->execute($input)->isFailed())->toBeTrue()
        ->and($provider->execute($input)->isSuccess())->toBeTrue()
        ->and($provider->execute($input)->isSuccess())->toBeTrue() // repete o último
        ->and($provider->calls())->toBe(3);
});
