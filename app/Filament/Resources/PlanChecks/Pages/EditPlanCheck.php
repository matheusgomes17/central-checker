<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanChecks\Pages;

use App\Filament\Resources\PlanChecks\PlanCheckResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;

class EditPlanCheck extends EditRecord
{
    protected static string $resource = PlanCheckResource::class;

    /**
     * @return array<Action|ActionGroup>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
