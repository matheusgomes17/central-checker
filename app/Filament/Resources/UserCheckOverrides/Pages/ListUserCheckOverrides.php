<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserCheckOverrides\Pages;

use App\Filament\Resources\UserCheckOverrides\UserCheckOverrideResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListUserCheckOverrides extends ListRecords
{
    protected static string $resource = UserCheckOverrideResource::class;

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
