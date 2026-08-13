<?php

declare(strict_types=1);

namespace Modules\Wallet\Providers;

use Modules\Core\Providers\ModuleServiceProvider;
use Modules\Wallet\Console\Commands\ExpireHolds;
use Override;

class WalletServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Wallet';
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
