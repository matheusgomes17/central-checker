<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Tables;

use App\Models\Team;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->copyable()->toggleable(),
                TextColumn::make('owner_name')
                    ->label(__('Owner'))
                    ->state(fn (Team $record): ?string => $record->owner()?->name)
                    ->placeholder(__('None')),
                TextColumn::make('memberships_count')
                    ->label(__('Members'))
                    ->counts('memberships')
                    ->sortable(),
                TextColumn::make('invitations_count')
                    ->label(__('Pending invitations'))
                    ->counts('invitations')
                    ->toggleable(),
                IconColumn::make('is_personal')->label(__('Personal'))->boolean()->sortable(),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make()->default(true),
                TernaryFilter::make('is_personal')->label(__('Personal team')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
