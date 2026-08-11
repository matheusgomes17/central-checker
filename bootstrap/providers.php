<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;

return [
    // O Core vem primeiro: é ele que registra os providers dos módulos, que
    // precisam estar no container antes do resto da aplicação. Os instaladores
    // do Filament, do Horizon e do Telescope reescrevem este arquivo em ordem
    // alfabética e sem strict types — se acontecer de novo, desfaça. O
    // Telescope não entra aqui de propósito: é dependência de desenvolvimento
    // e o AppServiceProvider o registra só em local.
    CoreServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
];
