<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserCheckOverrides\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UserCheckOverridesTable
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
                    ->searchable(),
                TextColumn::make('check.name')
                    ->label(__('Consulta'))
                    ->searchable(),
                IconColumn::make('blocked')
                    ->label(__('Bloqueado'))
                    ->boolean(),
                IconColumn::make('granted')
                    ->label(__('Liberado'))
                    ->boolean(),
                TextColumn::make('price_cents')
                    ->label(__('Preço'))
                    ->money('BRL', divideBy: 100)
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('blocked')
                    ->options([
                        '1' => __('Sim'),
                        '0' => __('Não'),
                    ]),
                TernaryFilter::make('granted')
                    ->label(__('Liberado')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
