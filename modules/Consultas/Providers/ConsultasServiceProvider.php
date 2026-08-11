<?php

declare(strict_types=1);

namespace Modules\Consultas\Providers;

use Modules\Consultas\Support\ProviderRegistry;
use Modules\Core\Providers\ModuleServiceProvider;
use Override;

class ConsultasServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Consultas';
    }

    #[Override]
    protected function registerModule(): void
    {
        // Singleton para as registrações de provider sobreviverem à requisição.
        // Providers reais entram aqui nas fases seguintes; em Fase A começa vazio.
        $this->app->singleton(ProviderRegistry::class);
    }
}
