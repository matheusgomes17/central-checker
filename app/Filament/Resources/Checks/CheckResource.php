<?php

declare(strict_types=1);

namespace App\Filament\Resources\Checks;

use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Filament\Resources\Checks\Schemas\CheckForm;
use App\Filament\Resources\Checks\Tables\ChecksTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\CheckerApi\Models\Check;
use Override;
use UnitEnum;

/**
 * The catalog operators build the product from.
 *
 * Unlike its siblings in this panel, this resource is editable: the check
 * catalog is authored here, not somewhere else the panel merely reflects.
 */
class CheckResource extends Resource
{
    protected static ?string $model = Check::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static UnitEnum|string|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 10;

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return CheckForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ChecksTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListChecks::route('/'),
            'create' => CreateCheck::route('/create'),
            'edit' => EditCheck::route('/{record}/edit'),
        ];
    }
}
