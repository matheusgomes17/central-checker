<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Team;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Override;

/**
 * The numbers an operator wants before reading anything else.
 *
 * MRR and churn belong here too, and are absent because billing does not exist
 * yet. Showing them as zero would be worse than showing nothing: a dashboard
 * that reports a made-up number teaches people not to trust the real ones.
 */
class PlatformOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    #[Override]
    protected function getStats(): array
    {
        return [
            Stat::make(__('Signups (7 days)'), $this->signupsWithin(7))
                ->description(__('30 days: :count', ['count' => $this->signupsWithin(30)]))
                ->descriptionIcon('heroicon-m-user-plus')
                ->chart($this->signupsPerDay()),

            Stat::make(__('Signups (90 days)'), $this->signupsWithin(90))
                ->description(__('All time: :count', ['count' => User::query()->count()]))
                ->descriptionIcon('heroicon-m-users'),

            Stat::make(__('Active teams'), $this->activeTeams())
                ->description(__('Excludes personal teams'))
                ->descriptionIcon('heroicon-m-user-group'),
        ];
    }

    private function signupsWithin(int $days): int
    {
        return User::query()->where('created_at', '>=', now()->subDays($days))->count();
    }

    /**
     * A team is only worth counting when it is more than someone's own account
     * under another name, so personal teams are left out.
     */
    private function activeTeams(): int
    {
        return Team::query()->where('is_personal', false)->count();
    }

    /**
     * @return array<int, int>
     */
    private function signupsPerDay(): array
    {
        $counts = User::query()
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->get(['created_at'])
            ->countBy(fn (User $user): string => (string) $user->created_at?->toDateString());

        return collect(range(6, 0))
            ->map(fn (int $ago): int => (int) $counts->get(now()->subDays($ago)->toDateString(), 0))
            ->all();
    }
}
