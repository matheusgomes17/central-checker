<?php

declare(strict_types=1);

namespace App\Filament\Resources\CheckExecutions;

use App\Filament\Resources\CheckExecutions\Pages\ListCheckExecutions;
use App\Filament\Resources\CheckExecutions\Tables\CheckExecutionsTable;
use App\Models\Scopes\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\CheckerApi\Models\CheckExecution;
use Override;
use UnitEnum;

/**
 * The audit trail of check runs — read-only, and deliberately blind to the
 * raw `result` column. That column holds a third party's unmasked personal
 * data (CPF/CNPJ enrichment); only `input_masked`, already masked at the
 * source, may ever reach this panel. See the LGPD guard test in
 * CheckExecutionResourceTest.
 *
 * The model is tenant scoped like every domain model, but the panel has no
 * ambient tenant — so the default query would always come back empty. This
 * resource drops the scope to see every team's executions, same as
 * WalletResource and WalletTransactionResource.
 */
class CheckExecutionResource extends Resource
{
    protected static ?string $model = CheckExecution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static UnitEnum|string|null $navigationGroup = 'Operação';

    protected static ?int $navigationSort = 13;

    protected static ?string $modelLabel = 'execução';

    protected static ?string $pluralModelLabel = 'execuções';

    /**
     * @return Builder<Model>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(TenantScope::class);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return CheckExecutionsTable::configure($table);
    }

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[Override]
    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    #[Override]
    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListCheckExecutions::route('/'),
        ];
    }
}
