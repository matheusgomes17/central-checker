<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Schemas;

use App\Models\Team;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TeamInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Team'))->schema([
                TextEntry::make('name'),
                TextEntry::make('slug')->copyable(),
                IconEntry::make('is_personal')->label(__('Personal'))->boolean(),
                TextEntry::make('created_at')->label(__('Created'))->dateTime(),
                TextEntry::make('deleted_at')->label(__('Deleted'))->dateTime()->placeholder(__('Active')),
            ])->columns(2),

            Section::make(__('Members'))->schema([
                TextEntry::make('members.name')
                    ->label(__('Members'))
                    ->badge()
                    ->placeholder(__('None')),
                TextEntry::make('owner')
                    ->label(__('Owner'))
                    ->state(fn (Team $record): ?string => $record->owner()?->name)
                    ->placeholder(__('None')),
            ]),

            Section::make(__('Pending invitations'))->schema([
                TextEntry::make('invitations.email')
                    ->label(__('Invited'))
                    ->badge()
                    ->placeholder(__('None')),
            ]),
        ]);
    }
}
