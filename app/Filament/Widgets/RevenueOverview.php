<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Support\Billing\Plan;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Number;
use Laravel\Cashier\Subscription;
use Override;

/**
 * Recurring revenue and churn, computed from the local subscription rows.
 *
 * Deliberately not asked of Stripe. A dashboard that makes an API call per
 * page load is a dashboard that breaks when Stripe is slow, and these rows are
 * kept in step by the webhooks anyway — if they are wrong, the number being
 * wrong is the symptom worth seeing.
 *
 * Trials are excluded from revenue: money that has not been charged is not
 * revenue, and counting it is how a forecast turns into a fiction.
 */
class RevenueOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    #[Override]
    protected function getStats(): array
    {
        return [
            Stat::make(__('MRR'), $this->formatMoney($this->monthlyRecurringRevenue()))
                ->description(__('Paying subscriptions: :count', ['count' => $this->payingCount()]))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make(__('Churn (30 days)'), $this->churnRate().'%')
                ->description(__('Cancelled: :count', ['count' => $this->cancelledWithin(30)]))
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color($this->churnRate() > 5 ? 'danger' : 'gray'),

            Stat::make(__('On trial'), $this->trialingCount())
                ->description(__('Not counted as revenue'))
                ->descriptionIcon('heroicon-m-clock'),
        ];
    }

    /**
     * Sum of the monthly price of every subscription actually being charged.
     */
    private function monthlyRecurringRevenue(): int
    {
        $prices = Plan::all();

        return Subscription::query()
            ->whereIn('stripe_status', ['active', 'past_due'])
            ->whereNull('ends_at')
            ->get()
            ->sum(function (Subscription $subscription) use ($prices): int {
                foreach ($prices as $plan) {
                    if ($plan->priceId !== null && $plan->priceId === $subscription->stripe_price) {
                        return $plan->price * max(1, (int) $subscription->quantity);
                    }
                }

                return 0;
            });
    }

    private function payingCount(): int
    {
        return Subscription::query()
            ->whereIn('stripe_status', ['active', 'past_due'])
            ->whereNull('ends_at')
            ->count();
    }

    private function trialingCount(): int
    {
        return Subscription::query()->where('stripe_status', 'trialing')->count();
    }

    private function cancelledWithin(int $days): int
    {
        return Subscription::query()
            ->whereNotNull('ends_at')
            ->where('ends_at', '>=', now()->subDays($days))
            ->count();
    }

    /**
     * Cancellations over the period against what there was to cancel.
     *
     * Zero when there was nothing to lose — a churn rate computed against an
     * empty base is a division by zero dressed up as a percentage.
     */
    private function churnRate(): float
    {
        $base = $this->payingCount() + $this->cancelledWithin(30);

        if ($base === 0) {
            return 0.0;
        }

        return round($this->cancelledWithin(30) / $base * 100, 1);
    }

    /**
     * Formatted with the currency and locale Cashier is configured with.
     *
     * Written by hand this became a branch per currency, which is the kind of
     * thing that is right until the second currency shows up.
     */
    private function formatMoney(int $cents): string
    {
        return (string) Number::currency(
            $cents / 100,
            in: strtoupper(Config::string('cashier.currency')),
            locale: Config::string('cashier.currency_locale'),
        );
    }
}
