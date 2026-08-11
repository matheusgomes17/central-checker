<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Onde procurar models
    |--------------------------------------------------------------------------
    |
    | O `tenant:audit` precisa saber quais tabelas têm model escopado, e para
    | isso precisa saber onde os models moram. O padrão cobre a camada de
    | aplicação e os módulos; projetos que organizam por domínio acrescentam os
    | seus caminhos aqui.
    |
    */

    'model_paths' => [
        app_path('Models'),
        ...glob(base_path('modules/*/Models')) ?: [],
    ],

];
