<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserCheckOverrides;

use App\Filament\Resources\UserCheckOverrides\Pages\CreateUserCheckOverride;
use App\Filament\Resources\UserCheckOverrides\Pages\EditUserCheckOverride;
use App\Filament\Resources\UserCheckOverrides\Pages\ListUserCheckOverrides;
use App\Filament\Resources\UserCheckOverrides\Schemas\UserCheckOverrideForm;
use App\Filament\Resources\UserCheckOverrides\Tables\UserCheckOverridesTable;
use App\Models\Scopes\TenantScope;
use App\Models\UserCheckOverride;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use UnitEnum;

/**
 * The per-user levers on top of a check: block, grant for free, or charge a
 * custom price. Editable, like `CheckResource` and `PlanCheckResource`.
 *
 * The model is tenant scoped like every domain model, but the panel has no
 * ambient tenant — so the default query would always come back empty. This
 * resource is the deliberate crossing: it drops the scope to see every
 * team's overrides, and the create page forces team_id back in by hand since
 * it is not in the model's Fillable list.
 */
class UserCheckOverrideResource extends Resource
{
    protected static ?string $model = UserCheckOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static UnitEnum|string|null $navigationGroup = 'Operação';

    protected static ?int $navigationSort = 12;

    protected static ?string $modelLabel = 'override';

    protected static ?string $pluralModelLabel = 'overrides';

    /**
     * @return Builder<Model>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(TenantScope::class);
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return UserCheckOverrideForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return UserCheckOverridesTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListUserCheckOverrides::route('/'),
            'create' => CreateUserCheckOverride::route('/create'),
            'edit' => EditUserCheckOverride::route('/{record}/edit'),
        ];
    }
}
