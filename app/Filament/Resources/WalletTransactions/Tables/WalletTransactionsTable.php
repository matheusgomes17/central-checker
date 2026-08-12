<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletTransactions\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Carteira\Enums\WalletTransactionType;

class WalletTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('team.name')
                    ->label(__('Time'))
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->badge()
                    ->formatStateUsing(fn (WalletTransactionType $state): string => self::typeLabel($state))
                    ->color(fn (WalletTransactionType $state): string => $state->isCredit() ? 'success' : 'danger'),
                TextColumn::make('amount_cents')
                    ->label(__('Valor'))
                    ->money('BRL', divideBy: 100),
                TextColumn::make('balance_after_cents')
                    ->label(__('Saldo após'))
                    ->money('BRL', divideBy: 100),
                TextColumn::make('description')
                    ->label(__('Descrição'))
                    ->wrap()
                    ->limit(60),
                TextColumn::make('created_at')
                    ->label(__('Quando'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Tipo'))
                    ->options(fn (): array => collect(WalletTransactionType::cases())
                        ->mapWithKeys(fn (WalletTransactionType $type): array => [$type->value => self::typeLabel($type)])
                        ->all()),
            ]);
    }

    private static function typeLabel(WalletTransactionType $type): string
    {
        return match ($type) {
            WalletTransactionType::Recharge => __('Recarga'),
            WalletTransactionType::Charge => __('Cobrança'),
            WalletTransactionType::Refund => __('Estorno'),
            WalletTransactionType::Bonus => __('Bônus'),
            WalletTransactionType::Adjustment => __('Ajuste'),
        };
    }
}
