<?php

declare(strict_types=1);

namespace Modules\Consultas\Providers;

use Modules\Core\Providers\ModuleServiceProvider;

class ConsultasServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Consultas';
    }
}
