<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserCheckOverrides\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class UserCheckOverrideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('team_id')
                    ->relationship('team', 'name')
                    ->label(__('Time'))
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->label(__('Usuário'))
                    ->required()
                    ->searchable(),
                Select::make('check_id')
                    ->relationship('check', 'name')
                    ->label(__('Consulta'))
                    ->required()
                    ->searchable()
                    // O trio (team_id, user_id, check_id) é único no banco
                    // (23000); isto antecipa o erro para o formulário antes
                    // de tentar o insert.
                    ->unique(ignoreRecord: true, modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                        $teamId = $get('team_id');
                        $userId = $get('user_id');

                        return $rule
                            ->where('team_id', is_int($teamId) || is_string($teamId) ? $teamId : null)
                            ->where('user_id', is_int($userId) || is_string($userId) ? $userId : null);
                    }),
                Toggle::make('blocked')
                    ->label(__('Bloqueado'))
                    ->helperText(__('Bloqueio vence tudo')),
                Toggle::make('granted')
                    ->label(__('Liberado (grátis)'))
                    ->helperText(__('Liberação ignora plano e preço; se Bloqueado também estiver marcado, Bloqueado vence')),
                TextInput::make('price_cents')
                    ->label(__('Preço custom'))
                    ->prefix('R$')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->nullable()
                    ->helperText(__('Vazio = usa o preço do catálogo'))
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : number_format($state / 100, 2, '.', ''))
                    ->dehydrateStateUsing(fn (?string $state): ?int => ($state === null || $state === '') ? null : (int) round(((float) $state) * 100)),
            ]);
    }
}
