<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog;

use App\Filament\Resources\ActivityLog\Pages\ListActivities;
use App\Filament\Resources\ActivityLog\Tables\ActivitiesTable;
use App\Support\Audit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail, read-only by construction.
 *
 * A trail an operator can edit or delete answers nothing: the first question
 * anyone asks of an audit log is whether it could have been changed. There is
 * no create, no edit, and no delete here, and that is the feature.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit trail';

    #[Override]
    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
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
            'index' => ListActivities::route('/'),
        ];
    }

    /**
     * Only the application's own audit entries.
     *
     * Anything else the activity log picks up later belongs somewhere else;
     * mixing it in here would dilute the one log people are meant to read.
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('log_name', Audit::LOG);
    }
}
