<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retenção após exclusão de conta
    |--------------------------------------------------------------------------
    |
    | Excluir a conta faz um soft delete: o usuário perde o acesso na hora, mas
    | os dados ficam retidos por este número de dias antes da remoção
    | definitiva. A janela existe para permitir arrependimento e para atender
    | pedidos de restauração dentro de um prazo razoável.
    |
    | Zero purga na primeira execução do comando após a exclusão.
    |
    */

    'purge_after_days' => (int) env('ACCOUNT_PURGE_AFTER_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Disco dos avatares
    |--------------------------------------------------------------------------
    |
    | O padrão é o s3, que no ambiente local aponta para o MinIO que sobe no
    | compose. O banco guarda o caminho, nunca a URL: bucket e endpoint mudam
    | entre ambientes e uma URL gravada envelhece mal.
    |
    */

    'avatar_disk' => env('AVATAR_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Idiomas oferecidos
    |--------------------------------------------------------------------------
    |
    | Restringe o que o usuário pode escolher no perfil. Aceitar qualquer
    | string aqui deixaria o app tentar carregar traduções que não existem.
    |
    */

    'locales' => [
        'pt_BR' => 'Português (Brasil)',
        'en' => 'English',
    ],

];
