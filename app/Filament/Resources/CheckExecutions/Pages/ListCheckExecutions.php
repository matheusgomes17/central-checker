<?php

declare(strict_types=1);

namespace App\Filament\Resources\CheckExecutions\Pages;

use App\Filament\Resources\CheckExecutions\CheckExecutionResource;
use Filament\Resources\Pages\ListRecords;

class ListCheckExecutions extends ListRecords
{
    protected static string $resource = CheckExecutionResource::class;
}
