<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Plan;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Override;

/**
 * Recurring revenue and churn, computed from the local prepaid subscription
 * rows.
 *
 * Deliberately not asked of an external API. A dashboard that makes a network
 * call per page load is a dashboard that breaks when that service is slow,
 * and these rows are the source of truth for the prepaid model anyway — if
 * they are wrong, the number being wrong is the symptom worth seeing.
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
     * Sum of the monthly-normalized price of every active subscription.
     *
     * Normalizing to 30 days keeps MRR honest if a plan's period is not
     * exactly a month long.
     */
    private function monthlyRecurringRevenue(): int
    {
        return $this->activeSubscriptions()
            ->get()
            ->sum(function (PlanSubscription $subscription): int {
                $plan = Plan::find($subscription->plan);

                return $plan->periodDays > 0
                    ? (int) round($plan->price * 30 / $plan->periodDays)
                    : $plan->price;
            });
    }

    private function payingCount(): int
    {
        return $this->activeSubscriptions()->count();
    }

    /**
     * Teams inside their trial window.
     *
     * An admin-widget approximation: it does not subtract teams that also
     * have an active subscription, so a team that upgraded mid-trial is
     * counted here as well as among the paying subscriptions.
     */
    private function trialingCount(): int
    {
        return Team::query()->where('trial_ends_at', '>', now())->count();
    }

    /**
     * Paid periods that lapsed without renewing, in the last `$days` days.
     *
     * A subscription whose `current_period_end` falls in the window and was
     * not extended is a lapse: a renewal would have pushed
     * `current_period_end` into the future, so a renewed row would not match
     * this range at all.
     */
    private function cancelledWithin(int $days): int
    {
        return PlanSubscription::query()
            ->whereBetween('current_period_end', [now()->subDays($days), now()])
            ->count();
    }

    /**
     * @return Builder<PlanSubscription>
     */
    private function activeSubscriptions(): Builder
    {
        return PlanSubscription::query()->where('current_period_end', '>', now());
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
     * Formatted in reais: the product is billed via Pix, so BRL/pt_BR is not
     * configuration, it is the only currency this application ever charges.
     */
    private function formatMoney(int $cents): string
    {
        return (string) Number::currency($cents / 100, in: 'BRL', locale: 'pt_BR');
    }
}
