<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\Subscription;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('team_id')
                    ->label(__('Team'))
                    ->state(function (Subscription $record): string {
                        $team = self::teamOf($record);

                        return $team instanceof Team ? $team->name : '—';
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                        'team_id',
                        Team::query()->where('name', 'like', "%{$search}%")->pluck('id'),
                    )),
                TextColumn::make('stripe_price')
                    ->label(__('Plan'))
                    ->formatStateUsing(function (?string $state): string {
                        $plan = Plan::forPriceId($state);

                        return $plan instanceof Plan ? $plan->name : ($state ?? '—');
                    })
                    ->badge(),
                TextColumn::make('stripe_status')->label(__('Stripe status'))->badge()->sortable(),
                TextColumn::make('status')
                    ->label(__('State'))
                    ->badge()
                    ->state(function (Subscription $record): string {
                        $billing = self::billingOf($record);

                        return $billing instanceof TeamBilling ? $billing->status()->label() : '—';
                    })
                    ->color(function (Subscription $record): string {
                        $billing = self::billingOf($record);

                        return $billing instanceof TeamBilling ? $billing->status()->color() : 'gray';
                    }),
                TextColumn::make('quantity')->label(__('Seats'))->sortable()->toggleable(),
                TextColumn::make('trial_ends_at')->label(__('Trial ends'))->dateTime()->toggleable(),
                TextColumn::make('ends_at')->label(__('Ends'))->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('created_at')->label(__('Started'))->dateTime()->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('stripe_status')
                    ->label(__('Stripe status'))
                    ->options([
                        'active' => 'active',
                        'trialing' => 'trialing',
                        'past_due' => 'past_due',
                        'canceled' => 'canceled',
                        'incomplete' => 'incomplete',
                        'unpaid' => 'unpaid',
                    ]),
                SelectFilter::make('stripe_price')
                    ->label(__('Plan'))
                    ->options(fn (): array => collect(Plan::purchasable())
                        ->filter(fn (Plan $plan): bool => $plan->priceId !== null)
                        ->mapWithKeys(fn (Plan $plan): array => [$plan->priceId => $plan->name])
                        ->all()),
            ]);
    }

    private static function teamOf(Subscription $subscription): ?Team
    {
        return Team::query()->whereKey($subscription->getAttribute('team_id'))->first();
    }

    private static function billingOf(Subscription $subscription): ?TeamBilling
    {
        $team = self::teamOf($subscription);

        return $team instanceof Team ? TeamBilling::for($team) : null;
    }
}
