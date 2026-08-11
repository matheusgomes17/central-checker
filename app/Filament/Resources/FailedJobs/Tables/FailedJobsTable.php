<?php

declare(strict_types=1);

namespace App\Filament\Resources\FailedJobs\Tables;

use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;

class FailedJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('failed_at')->label(__('Failed'))->dateTime()->sortable(),
                TextColumn::make('display_name')
                    ->label(__('Job'))
                    ->state(fn (FailedJob $record): string => $record->displayName())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('payload', 'like', "%{$search}%")),
                TextColumn::make('queue')->sortable(),
                TextColumn::make('connection')->toggleable(),
                TextColumn::make('exception_summary')
                    ->label(__('Error'))
                    ->state(fn (FailedJob $record): string => $record->exceptionSummary())
                    ->wrap(),
            ])
            ->defaultSort('failed_at', 'desc')
            ->filters([
                SelectFilter::make('queue')
                    ->options(fn (): array => FailedJob::query()
                        ->distinct()
                        ->pluck('queue', 'queue')
                        ->all()),
            ])
            ->recordActions([
                self::retry(),
                self::forget(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::retryBulk(),
                ]),
            ]);
    }

    /**
     * Retrying and forgetting go through Artisan, not through Eloquent.
     *
     * The queue keeps its own bookkeeping — deleting the row by hand would
     * leave the job un-run and the record gone. The model here is a window,
     * not an owner.
     */
    private static function retry(): Action
    {
        return Action::make('retry')
            ->label(__('Retry'))
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->action(function (FailedJob $record): void {
                Artisan::call('queue:retry', ['id' => [$record->uuid]]);

                Notification::make()->title(__('Job queued for retry.'))->success()->send();
            });
    }

    private static function forget(): Action
    {
        return Action::make('forget')
            ->label(__('Forget'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (FailedJob $record): void {
                Artisan::call('queue:forget', ['id' => $record->uuid]);

                Notification::make()->title(__('Failed job removed.'))->success()->send();
            });
    }

    private static function retryBulk(): BulkAction
    {
        return BulkAction::make('retrySelected')
            ->label(__('Retry selected'))
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                Artisan::call('queue:retry', ['id' => $records->pluck('uuid')->all()]);

                Notification::make()->title(__(':count job(s) queued for retry.', [
                    'count' => $records->count(),
                ]))->success()->send();
            });
    }
}
