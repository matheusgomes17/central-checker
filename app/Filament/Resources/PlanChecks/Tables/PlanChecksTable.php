<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanChecks\Tables;

use App\Support\Billing\Plan;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlanChecksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::planName($state)),
                TextColumn::make('check.name')
                    ->label(__('Check'))
                    ->searchable(),
                TextColumn::make('quota')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Ilimitado' : (string) $state)
                    ->badge()
                    ->color(fn (?int $state): string => $state === null ? 'success' : 'gray'),
                TextColumn::make('updated_at')->dateTime()->toggleable(),
            ])
            ->defaultSort('plan')
            ->filters([
                SelectFilter::make('plan')
                    ->options(fn (): array => collect(Plan::all())
                        ->mapWithKeys(fn (Plan $p): array => [$p->key => $p->name])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * The plan's display name, or the raw stored key when the row's plan is
     * no longer in configuration (renamed or removed after the fact).
     */
    private static function planName(string $state): string
    {
        $plan = Plan::tryFind($state);

        return $plan instanceof Plan ? $plan->name : $state;
    }
}
