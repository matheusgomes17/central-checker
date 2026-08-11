<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Módulos habilitados
    |--------------------------------------------------------------------------
    |
    | Cada entrada corresponde a um diretório em modules/ e faz o Core registrar
    | Modules\<Nome>\Providers\<Nome>ServiceProvider. A ordem é a ordem de
    | registro: um módulo só pode depender dos que vêm antes dele.
    |
    | Para remover um módulo do boilerplate, tire-o daqui e apague o diretório.
    |
    */

    'enabled' => [
        'Carteira',
        'Consultas',
        //
    ],

];
