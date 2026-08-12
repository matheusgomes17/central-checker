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
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;

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
                self::creditar(),
            ]);
    }

    /**
     * The manual credit — an operator adjustment, wired straight through
     * CreditWallet so it locks the wallet row and writes the ledger line
     * itself. Never touches balance_cents directly.
     */
    private static function creditar(): Action
    {
        return Action::make('creditar')
            ->label(__('Creditar'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema([
                TextInput::make('valor')
                    ->label(__('Valor'))
                    ->prefix('R$')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
                Textarea::make('descricao')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Wallet $record, array $data): void {
                $team = $record->team;
                $valor = $data['valor'] ?? null;
                $descricao = $data['descricao'] ?? null;

                if (! $team instanceof Team || ! is_numeric($valor) || ! is_string($descricao)) {
                    Notification::make()
                        ->title(__('Não foi possível creditar'))
                        ->body(__('Dados inválidos. Confira o valor e o motivo.'))
                        ->danger()
                        ->send();

                    return;
                }

                resolve(CreditWallet::class)->handle(
                    $team,
                    (int) round(((float) $valor) * 100),
                    WalletTransactionType::Adjustment,
                    $descricao,
                );

                Notification::make()
                    ->title(__('Carteira creditada.'))
                    ->success()
                    ->send();
            });
    }
}
