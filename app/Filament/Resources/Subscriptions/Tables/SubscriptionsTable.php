<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Models\PlanSubscription;
use App\Support\Billing\Plan;
use App\Support\Billing\SubscriptionStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('team.name')
                    ->label(__('Team'))
                    ->searchable(),
                TextColumn::make('plan')
                    ->label(__('Plan'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Plan::all()[$state]?->name ?? $state),
                TextColumn::make('state')
                    ->label(__('State'))
                    ->badge()
                    ->state(fn (PlanSubscription $record) => self::rowStatus($record)->label())
                    ->color(fn (PlanSubscription $record) => self::rowStatus($record)->color()),
                TextColumn::make('current_period_end')
                    ->label(__('Renews / ends'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('canceled_at')
                    ->label(__('Canceled'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('Started'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('plan')
                    ->label(__('Plan'))
                    ->options(fn (): array => collect(Plan::purchasable())
                        ->mapWithKeys(fn (Plan $plan): array => [$plan->key => $plan->name])
                        ->all()),
            ]);
    }

    /**
     * The status of this specific row, not the team's current global status.
     *
     * A team can have more than one row over time; the team's `TeamBilling`
     * status answers "what plan is the team on now", which is not the same
     * question as "what happened to this particular period".
     */
    private static function rowStatus(PlanSubscription $r): SubscriptionStatus
    {
        if (! $r->isActive()) {
            return SubscriptionStatus::Expired;
        }

        return $r->isExpiring() ? SubscriptionStatus::Expiring : SubscriptionStatus::Active;
    }
}
