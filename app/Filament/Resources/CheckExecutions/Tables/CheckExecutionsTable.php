<?php

declare(strict_types=1);

namespace App\Filament\Resources\CheckExecutions\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\CheckExecution;

class CheckExecutionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('team.name')
                    ->label(__('Time'))
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label(__('Usuário'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('check.name')
                    ->label(__('Consulta'))
                    ->searchable(),
                TextColumn::make('outcome')
                    ->label(__('Desfecho'))
                    ->badge()
                    ->formatStateUsing(fn (ProviderOutcome $state): string => self::outcomeLabel($state))
                    ->color(fn (ProviderOutcome $state): string => match ($state) {
                        ProviderOutcome::Success => 'success',
                        ProviderOutcome::NoData => 'gray',
                        ProviderOutcome::Failed => 'danger',
                    }),
                IconColumn::make('consumed_franquia')
                    ->label(__('Franquia'))
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('duration_ms')
                    ->label(__('Duração'))
                    ->numeric()
                    ->suffix(' ms')
                    ->toggleable(),
                TextColumn::make('input_masked')
                    ->label(__('Entrada'))
                    // ->state() evita o comportamento padrão de lista do TextColumn
                    // para array (Arr::wrap iteraria cada valor mascarado
                    // separadamente); aqui a linha inteira já sai formatada.
                    ->state(fn (CheckExecution $record): string => collect($record->input_masked)
                        ->map(fn (mixed $value, string $key): string => "{$key}: {$value}")
                        ->implode(', ')),
                TextColumn::make('failure_reason')
                    ->label(__('Falha'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('Quando'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('outcome')
                    ->label(__('Desfecho'))
                    ->options(fn (): array => collect(ProviderOutcome::cases())
                        ->mapWithKeys(fn (ProviderOutcome $outcome): array => [$outcome->value => self::outcomeLabel($outcome)])
                        ->all()),
                SelectFilter::make('check_id')
                    ->label(__('Consulta'))
                    ->relationship('check', 'name'),
                SelectFilter::make('team_id')
                    ->label(__('Time'))
                    ->relationship('team', 'name'),
            ]);
    }

    private static function outcomeLabel(ProviderOutcome $outcome): string
    {
        return match ($outcome) {
            ProviderOutcome::Success => __('Sucesso'),
            ProviderOutcome::NoData => __('Sem dados'),
            ProviderOutcome::Failed => __('Falhou'),
        };
    }
}
