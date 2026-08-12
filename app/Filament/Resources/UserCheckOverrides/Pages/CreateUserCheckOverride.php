<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserCheckOverrides\Pages;

use App\Filament\Resources\UserCheckOverrides\UserCheckOverrideResource;
use App\Models\UserCheckOverride;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Override;

class CreateUserCheckOverride extends CreateRecord
{
    protected static string $resource = UserCheckOverrideResource::class;

    /**
     * team_id is deliberately outside the model's Fillable list — the trait
     * normally fills it from the ambient tenant, but the panel has none. The
     * form's own Select carries it instead, so creation has to force it in
     * by hand rather than go through the default mass-assigned create().
     *
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordCreation(array $data): Model
    {
        return UserCheckOverride::withoutTenancy(function () use ($data): UserCheckOverride {
            $record = new UserCheckOverride;
            $record->forceFill($data)->save();

            return $record;
        });
    }
}
