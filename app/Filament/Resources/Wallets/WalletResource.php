<?php

declare(strict_types=1);

namespace App\Filament\Resources\Wallets;

use App\Filament\Resources\Wallets\Pages\ListWallets;
use App\Filament\Resources\Wallets\Tables\WalletsTable;
use App\Models\Scopes\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Models\Wallet;
use Override;
use UnitEnum;

/**
 * The team's balance, read-only — the row action that credits the wallet is
 * the one deliberate write, and it goes through CreditWallet rather than
 * touching balance_cents directly, so every credit lands as a ledger line
 * too.
 *
 * The model is tenant scoped like every domain model, but the panel has no
 * ambient tenant — so the default query would always come back empty. This
 * resource drops the scope to see every team's wallet, same as
 * UserCheckOverrideResource.
 */
class WalletResource extends Resource
{
    protected static ?string $model = Wallet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static UnitEnum|string|null $navigationGroup = 'Faturamento';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'carteira';

    protected static ?string $pluralModelLabel = 'carteiras';

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
        return WalletsTable::configure($table);
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
            'index' => ListWallets::route('/'),
        ];
    }
}
