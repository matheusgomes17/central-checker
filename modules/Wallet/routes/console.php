<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Modules\Wallet\Console\Commands\ExpireHolds;

// Hold órfão prende saldo; varrer de minuto em minuto mantém a janela curta.
Schedule::command(ExpireHolds::class)->everyMinute()->withoutOverlapping();
