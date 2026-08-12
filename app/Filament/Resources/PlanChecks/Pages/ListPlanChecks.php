<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanChecks\Pages;

use App\Filament\Resources\PlanChecks\PlanCheckResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListPlanChecks extends ListRecords
{
    protected static string $resource = PlanCheckResource::class;

    /**
     * @return array<Action|ActionGroup>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
