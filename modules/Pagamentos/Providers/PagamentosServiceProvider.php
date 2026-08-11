<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Providers;

use Modules\Core\Providers\ModuleServiceProvider;

class PagamentosServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Pagamentos';
    }
}
