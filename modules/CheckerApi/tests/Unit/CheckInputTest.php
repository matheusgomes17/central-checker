<?php

declare(strict_types=1);

use Modules\CheckerApi\Support\CheckInput;

it('devolve os parâmetros crus', function (): void {
    $input = CheckInput::fromArray(['cpf' => '12345678901']);

    expect($input->all())->toBe(['cpf' => '12345678901'])
        ->and($input->get('cpf'))->toBe('12345678901')
        ->and($input->get('ausente'))->toBeNull();
});

it('mascara valores longos preservando pontas', function (): void {
    $input = CheckInput::fromArray(['cpf' => '12345678901', 'uf' => 'SP']);

    expect($input->masked())->toBe([
        'cpf' => '123******01',
        'uf' => 'SP', // curto demais para mascarar
    ]);
});
