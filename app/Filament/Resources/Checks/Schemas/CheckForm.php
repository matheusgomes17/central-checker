<?php

declare(strict_types=1);

namespace App\Filament\Resources\Checks\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\CheckerApi\Enums\CheckStatus;
use Modules\CheckerApi\Support\ProviderRegistry;

class CheckForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->rule('alpha_dash'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('description')
                    ->nullable(),
                TextInput::make('category')
                    ->nullable(),
                Select::make('provider_key')
                    ->options(fn (): array => collect(resolve(ProviderRegistry::class)->keys())
                        ->mapWithKeys(fn (string $key): array => [$key => $key])
                        ->all())
                    ->required()
                    ->native(false),
                TextInput::make('price_cents')
                    ->label(__('Preço'))
                    ->prefix('R$')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required()
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : number_format($state / 100, 2, '.', ''))
                    ->dehydrateStateUsing(fn (?string $state): int => (int) round(((float) $state) * 100)),
                Select::make('status')
                    ->options(CheckStatus::class)
                    ->default(CheckStatus::Active)
                    ->required()
                    ->native(false),
                TextInput::make('timeout_seconds')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(600)
                    ->default(30)
                    ->required(),
            ]);
    }
}
