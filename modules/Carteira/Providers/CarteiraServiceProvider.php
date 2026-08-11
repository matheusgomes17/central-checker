<?php

declare(strict_types=1);

namespace Modules\Carteira\Providers;

use Modules\Carteira\Console\Commands\ExpireHolds;
use Modules\Core\Providers\ModuleServiceProvider;
use Override;

class CarteiraServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Carteira';
    }

    // Comandos em modules/*/Console/Commands não são descobertos
    // automaticamente (mesma situação de MakeModuleCommand em Core);
    // precisam de registro explícito.
    #[Override]
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireHolds::class]);
        }
    }
}
