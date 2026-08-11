<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;

/*
|--------------------------------------------------------------------------
| Entrar pela tela de verdade
|--------------------------------------------------------------------------
|
| O formulário de login é montado com Flux e o campo de senha tem o botão de
| revelar, que é JavaScript. Um teste de feature posta direto na rota e passa
| mesmo que o formulário na tela esteja quebrado — este preenche e envia.
|
| As asserções entram pela forma de função, e não por $this->: dentro de um
| closure do Pest o PHPStan tipa $this como TestCall e não encontra os métodos.
|
*/

it('entra pelo formulário e cai no painel do time atual', function (): void {
    $user = User::factory()->create(['email' => 'ana@example.test']);

    $page = visit('/login');

    $page->assertSee('Log in to your account')
        ->type('email', 'ana@example.test')
        ->type('password', 'password')
        ->click('@login-button')
        ->assertPathEndsWith('/dashboard')
        ->assertNoJavaScriptErrors();

    assertAuthenticatedAs($user);
});

it('recusa a senha errada e mantém o visitante fora', function (): void {
    User::factory()->create(['email' => 'ana@example.test']);

    $page = visit('/login');

    $page->type('email', 'ana@example.test')
        ->type('password', 'senha-errada')
        ->click('@login-button')
        ->assertPathIs('/login')
        ->assertSee('These credentials do not match our records.');

    assertGuest();
});
