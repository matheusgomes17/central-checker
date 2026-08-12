<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletTransactions;

use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Resources\WalletTransactions\Tables\WalletTransactionsTable;
use App\Models\Scopes\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Carteira\Models\WalletTransaction;
use Override;
use UnitEnum;

/**
 * The ledger — append-only by design. A correction is another line, never an
 * edit, so this resource stays entirely read-only.
 *
 * The model is tenant scoped like every domain model, but the panel has no
 * ambient tenant — so the default query would always come back empty. This
 * resource drops the scope to see every team's ledger lines, same as
 * WalletResource.
 */
class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static UnitEnum|string|null $navigationGroup = 'Faturamento';

    protected static ?int $navigationSort = 21;

    protected static ?string $modelLabel = 'lançamento';

    protected static ?string $pluralModelLabel = 'razão';

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
        return WalletTransactionsTable::configure($table);
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
            'index' => ListWalletTransactions::route('/'),
        ];
    }
}
