<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provedores de login social habilitados
    |--------------------------------------------------------------------------
    |
    | A chave é o driver do Socialite e serve de allowlist: qualquer outro
    | valor na rota devolve 404, para que o parâmetro de URL não vire um jeito
    | de sondar drivers.
    |
    | Os escopos não são cosméticos. No GitHub, "user:email" é o que faz o
    | Socialite trocar o e-mail do perfil público pelo primário verificado —
    | sem esse escopo o e-mail que chega não tem garantia de verificação, e o
    | merge de conta deixaria de ser seguro.
    |
    */

    'providers' => [

        'google' => [
            'label' => 'Google',
            'scopes' => [],
        ],

        'github' => [
            'label' => 'GitHub',
            'scopes' => ['user:email'],
        ],

    ],

];
