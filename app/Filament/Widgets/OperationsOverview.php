<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\Wallet\Models\Wallet;
use Override;

/**
 * Usage and prepaid cash, not revenue.
 *
 * MRR and churn already live in RevenueOverview and are deliberately absent
 * here: that widget answers "how much money comes in," this one answers "how
 * hard is the platform working and how much prepaid balance is parked in it."
 * The wallet sum is a liability sitting in the system, not income.
 *
 * CheckExecution and Wallet are both BelongsToTenant, and this panel runs
 * with no ambient tenant — every aggregate below crosses tenants explicitly
 * with acrossTenants(), or it silently reads 0.
 */
class OperationsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    #[Override]
    protected function getStats(): array
    {
        $successRate = $this->successRate();

        return [
            Stat::make(__('Consultas (hoje)'), $this->executionsToday())
                ->description(__('7 dias: :count', ['count' => $this->executionsWithin(7)]))
                ->descriptionIcon('heroicon-m-bolt')
                ->chart($this->executionsPerDay()),

            Stat::make(__('Taxa de sucesso (30 dias)'), $this->successRateLabel($successRate))
                ->description($successRate === null ? __('Sem consultas') : __('Últimos 30 dias'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($this->successRateColor($successRate)),

            Stat::make(__('Saldo agregado das carteiras'), $this->formatMoney($this->aggregateBalanceCents()))
                ->description(__(':count carteiras', ['count' => $this->walletsCount()]))
                ->descriptionIcon('heroicon-m-banknotes'),
        ];
    }

    private function executionsToday(): int
    {
        return CheckExecution::acrossTenants()->whereDate('created_at', today())->count();
    }

    private function executionsWithin(int $days): int
    {
        return CheckExecution::acrossTenants()->where('created_at', '>=', now()->subDays($days))->count();
    }

    /**
     * @return array<int, int>
     */
    private function executionsPerDay(): array
    {
        $counts = CheckExecution::acrossTenants()
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->get(['created_at'])
            ->countBy(fn (CheckExecution $execution): string => (string) $execution->created_at?->toDateString());

        return collect(range(6, 0))
            ->map(fn (int $ago): int => (int) $counts->get(now()->subDays($ago)->toDateString(), 0))
            ->all();
    }

    /**
     * Share of successful executions over the last 30 days. Null — not 0% —
     * when nothing ran in the window: an empty base is not a real failure
     * rate, and showing one would teach the operator to distrust the number.
     */
    private function successRate(): ?float
    {
        $total = $this->executionsWithin(30);

        if ($total === 0) {
            return null;
        }

        $success = CheckExecution::acrossTenants()
            ->where('created_at', '>=', now()->subDays(30))
            ->where('outcome', ProviderOutcome::Success->value)
            ->count();

        return round($success / $total * 100, 1);
    }

    private function successRateLabel(?float $rate): string
    {
        return $rate === null ? '—' : "{$rate}%";
    }

    private function successRateColor(?float $rate): string
    {
        if ($rate === null) {
            return 'gray';
        }

        return match (true) {
            $rate >= 90 => 'success',
            $rate >= 70 => 'warning',
            default => 'danger',
        };
    }

    private function aggregateBalanceCents(): int
    {
        return (int) Wallet::acrossTenants()->sum('balance_cents');
    }

    private function walletsCount(): int
    {
        return Wallet::acrossTenants()->count();
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
