<?php

declare(strict_types=1);

namespace App\Filament\Resources\Checks\Tables;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\CheckerApi\Enums\CheckStatus;
use Modules\CheckerApi\Models\Check;

class ChecksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('category')->badge()->toggleable(),
                TextColumn::make('provider_key')->badge(),
                TextColumn::make('price_cents')->label(__('Price'))->money('BRL', divideBy: 100),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (CheckStatus $state): string => match ($state) {
                        CheckStatus::Active => 'success',
                        CheckStatus::Maintenance => 'warning',
                        CheckStatus::Inactive => 'gray',
                    }),
                TextColumn::make('updated_at')->dateTime()->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')->options(CheckStatus::class),
            ])
            ->recordActions([
                self::toggleMaintenance(),
                EditAction::make(),
            ]);
    }

    /**
     * The "maintenance toggle" from D7 — flip Active <-> Maintenance without
     * opening the form. Inactive is left alone: it is a deliberate retirement,
     * not a temporary state this action should undo.
     */
    private static function toggleMaintenance(): Action
    {
        return Action::make('toggleMaintenance')
            ->label(fn (Check $record): string => $record->status === CheckStatus::Maintenance ? __('Reativar') : __('Manutenção'))
            ->action(fn (Check $record) => $record->update([
                'status' => $record->status === CheckStatus::Maintenance ? CheckStatus::Active : CheckStatus::Maintenance,
            ]))
            ->requiresConfirmation();
    }
}
