<?php

declare(strict_types=1);

namespace App\Filament\Resources\Wallets\Tables;

use App\Models\Team;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Wallet\Actions\CreditWallet;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;

class WalletsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('team.name')
                    ->label(__('Time'))
                    ->searchable(),
                TextColumn::make('balance_cents')
                    ->label(__('Saldo'))
                    ->money('BRL', divideBy: 100),
                TextColumn::make('available')
                    ->label(__('Disponível'))
                    ->state(fn (Wallet $record): int => $record->available())
                    ->money('BRL', divideBy: 100),
            ])
            ->defaultSort('balance_cents', 'desc')
            ->recordActions([
                self::credit(),
            ]);
    }

    /**
     * The manual credit — an operator adjustment, wired straight through
     * CreditWallet so it locks the wallet row and writes the ledger line
     * itself. Never touches balance_cents directly.
     */
    private static function credit(): Action
    {
        return Action::make('credit')
            ->label(__('Creditar'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema([
                TextInput::make('amount')
                    ->label(__('Valor'))
                    ->prefix('R$')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Wallet $record, array $data): void {
                $team = $record->team;
                $amount = $data['amount'] ?? null;
                $reason = $data['reason'] ?? null;

                if (! $team instanceof Team || ! is_numeric($amount) || ! is_string($reason)) {
                    Notification::make()
                        ->title(__('Não foi possível creditar'))
                        ->body(__('Dados inválidos. Confira o valor e o motivo.'))
                        ->danger()
                        ->send();

                    return;
                }

                resolve(CreditWallet::class)->handle(
                    $team,
                    (int) round(((float) $amount) * 100),
                    WalletTransactionType::Adjustment,
                    $reason,
                );

                Notification::make()
                    ->title(__('Carteira creditada.'))
                    ->success()
                    ->send();
            });
    }
}
