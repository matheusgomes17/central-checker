<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanChecks\Schemas;

use App\Support\Billing\Plan;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class PlanCheckForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('plan')
                    ->options(fn (): array => collect(Plan::all())
                        ->mapWithKeys(fn (Plan $p): array => [$p->key => $p->name])
                        ->all())
                    ->required()
                    ->native(false),
                Select::make('check_id')
                    ->relationship('check', 'name')
                    ->required()
                    ->native(false)
                    ->searchable()
                    // The (plan, check_id) pair is unique in the database (23000); this
                    // surfaces it as a form error before the insert is ever attempted.
                    ->unique(ignoreRecord: true, modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                        $plan = $get('plan');

                        return $rule->where('plan', is_string($plan) ? $plan : null);
                    }),
                TextInput::make('quota')
                    ->numeric()
                    ->minValue(1)
                    ->nullable()
                    ->helperText('Vazio = ilimitado'),
            ]);
    }
}
