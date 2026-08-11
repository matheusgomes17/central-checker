<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fumaça nas páginas públicas
|--------------------------------------------------------------------------
|
| O teste de feature garante que a rota responde 200; ele não vê o que o
| navegador faz depois. Este vê: Livewire, Alpine e Flux só falham quando o
| JavaScript roda, e um erro no console não muda o código de status.
|
| Verificado injetando cada caso na welcome.blade: assertNoSmoke pega exceção
| não capturada e console.log, e NÃO pega console.error. Quem quiser barrar
| console.error precisa de asserção própria — não conte com esta.
|
*/

it('serve as páginas públicas sem erro de javascript', function (): void {
    visit(['/', '/login', '/register', '/forgot-password'])->assertNoSmoke();
});
