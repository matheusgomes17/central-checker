<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;

/**
 * What an operator needs on the screen before answering a ticket.
 *
 * Grouped by the question being asked rather than by table: whether the person
 * can sign in, which teams they are in, and what state their account is in.
 */
class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Account'))->schema([
                TextEntry::make('name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('created_at')->label(__('Signed up'))->dateTime(),
                TextEntry::make('deleted_at')
                    ->label(__('Deleted'))
                    ->dateTime()
                    ->placeholder(__('Active'))
                    // O cast existe porque __() declara array|string|null: a
                    // variante array só aparece quando a chave aponta para um
                    // array de traduções, que não é o caso aqui.
                    ->helperText(fn (User $record): ?string => $record->deleted_at === null
                        ? null
                        : (string) __('Data is erased :days days after deletion.', [
                            'days' => (string) Config::integer('accounts.purge_after_days'),
                        ])),
            ])->columns(2),

            Section::make(__('Sign in'))->schema([
                IconEntry::make('email_verified_at')->label(__('Email verified'))->boolean(),
                IconEntry::make('two_factor_confirmed_at')->label(__('Two-factor enabled'))->boolean(),
                IconEntry::make('is_super_admin')->label(__('Platform administrator'))->boolean(),
                TextEntry::make('passkeys_count')
                    ->label(__('Passkeys'))
                    ->state(fn (User $record): int => $record->passkeys()->count()),
                TextEntry::make('socialAccounts.provider')
                    ->label(__('Social sign in'))
                    ->badge()
                    ->placeholder(__('None')),
            ])->columns(2),

            Section::make(__('Preferences'))->schema([
                TextEntry::make('locale')->placeholder(__('Application default')),
                TextEntry::make('timezone')->placeholder(__('Application default')),
            ])->columns(2),

            Section::make(__('Teams'))->schema([
                TextEntry::make('teams.name')
                    ->label(__('Member of'))
                    ->badge()
                    ->placeholder(__('None')),
            ]),
        ]);
    }
}
