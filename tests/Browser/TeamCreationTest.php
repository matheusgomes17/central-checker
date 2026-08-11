<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

/*
|--------------------------------------------------------------------------
| Criar time pelo modal
|--------------------------------------------------------------------------
|
| Criar um time passa por um modal do Flux aberto por Alpine, um formulário
| ligado por wire:model e um redirecionamento com wire:navigate. O teste de
| feature chama o método do componente direto e pula os três.
|
| O assertSee depois de abrir o modal não é enfeite: o Playwright espera
| indefinidamente por um elemento invisível, então sem essa asserção um modal
| que parar de abrir trava o job em vez de falhar. Verificado.
|
*/

it('cria um time pelo modal e leva para a página do time', function (): void {
    actingAs(User::factory()->create());

    $page = visit('/settings/teams');

    $page->click('@teams-new-team-button')
        ->assertSee('Create a new team')
        ->type('@create-team-name', 'Time do Financeiro')
        ->click('@create-team-submit')
        ->assertPathContains('/settings/teams/time-do-financeiro')
        ->assertNoJavaScriptErrors();

    assertDatabaseHas('teams', [
        'name' => 'Time do Financeiro',
        'slug' => 'time-do-financeiro',
        'is_personal' => false,
    ]);
});

it('mostra o erro de validação sem fechar o modal', function (): void {
    actingAs(User::factory()->create());

    $page = visit('/settings/teams');

    $page->click('@teams-new-team-button')
        ->assertSee('Create a new team')
        ->type('@create-team-name', ' ')
        ->click('@create-team-submit')
        ->assertSee('The name field is required.')
        ->assertPathIs('/settings/teams');

    assertDatabaseCount('teams', 1);
});
