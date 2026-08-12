<?php

declare(strict_types=1);

namespace App\Filament\Resources\Checks\Pages;

use App\Filament\Resources\Checks\CheckResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListChecks extends ListRecords
{
    protected static string $resource = CheckResource::class;

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
