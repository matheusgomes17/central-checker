<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Tables\SubscriptionsTable;
use App\Models\PlanSubscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

/**
 * Read-only, because the payment webhook owns these rows.
 *
 * Every row here is written by the VirtualPay/Pix webhook. Editing it in the
 * panel would put the panel and the source of truth out of step, and the next
 * webhook would silently undo whatever was typed.
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = PlanSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 25;

    #[Override]
    public static function table(Table $table): Table
    {
        return SubscriptionsTable::configure($table);
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
            'index' => ListSubscriptions::route('/'),
        ];
    }
}
