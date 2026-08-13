<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanChecks;

use App\Filament\Resources\PlanChecks\Pages\CreatePlanCheck;
use App\Filament\Resources\PlanChecks\Pages\EditPlanCheck;
use App\Filament\Resources\PlanChecks\Pages\ListPlanChecks;
use App\Filament\Resources\PlanChecks\Schemas\PlanCheckForm;
use App\Filament\Resources\PlanChecks\Tables\PlanChecksTable;
use App\Models\PlanCheck;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;
use UnitEnum;

/**
 * Which checks a plan includes, and with how large an allowance (quota).
 *
 * A null quota means unlimited — see `PlanCheck::isUnlimited()`. Editable,
 * like `CheckResource`: this is where the operator authors the catalog, not
 * a reflection of it.
 */
class PlanCheckResource extends Resource
{
    protected static ?string $model = PlanCheck::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 11;

    protected static ?string $modelLabel = 'inclusão de plano';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return PlanCheckForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return PlanChecksTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPlanChecks::route('/'),
            'create' => CreatePlanCheck::route('/create'),
            'edit' => EditPlanCheck::route('/{record}/edit'),
        ];
    }
}
