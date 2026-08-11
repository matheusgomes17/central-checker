<?php

declare(strict_types=1);

namespace Modules\Carteira\Providers;

use Modules\Core\Providers\ModuleServiceProvider;

class CarteiraServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Carteira';
    }
}
