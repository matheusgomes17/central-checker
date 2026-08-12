<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanChecks\Pages;

use App\Filament\Resources\PlanChecks\PlanCheckResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlanCheck extends CreateRecord
{
    protected static string $resource = PlanCheckResource::class;
}
