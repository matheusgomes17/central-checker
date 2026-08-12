<?php

declare(strict_types=1);

use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Subscriptions;
use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Carteira\Models\Wallet;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\CheckExecution;

new #[Title('Dashboard')] class extends Component {
    /**
     * The rolling window behind every number on this screen. The paid period
     * can start on any day of the month, so "recent activity" is a fixed
     * 30-day window here, independent of which plan the team is on — a
     * different question from Franquia's per-check quota, which does align
     * to the subscription cycle.
     */
    private const int WINDOW_DAYS = 30;

    public function mount(): void
    {
        abort_unless($this->team() !== null, 404);
    }

    #[Computed]
    public function billing(): TeamBilling
    {
        return TeamBilling::for($this->team());
    }

    #[Computed]
    public function consultasNoPeriodo(): int
    {
        return CheckExecution::query()
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->count();
    }

    /**
     * Share of successful executions in the window. Null — never a false
     * 0% — when nothing ran: an empty base is not a real failure rate, and
     * showing one would teach whoever reads it to distrust the number.
     */
    #[Computed]
    public function taxaSucesso(): ?float
    {
        $total = $this->consultasNoPeriodo;

        if ($total === 0) {
            return null;
        }

        $sucessos = CheckExecution::query()
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where('outcome', ProviderOutcome::Success->value)
            ->count();

        return round($sucessos / $total * 100, 1);
    }

    #[Computed]
    public function saldoCents(): int
    {
        return Wallet::forTeam($this->team())->balance_cents;
    }

    /**
     * Days left in the active paid period, ceiled so any partial day still
     * reads as "at least one day left" instead of rounding down to zero.
     * Null when there is no active subscription — the free plan has no
     * period to count down.
     */
    #[Computed]
    public function diasRestantes(): ?int
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return null;
        }

        return (int) ceil(now()->diffInSeconds($subscription->current_period_end) / 86400);
    }

    /**
     * Share of the paid period still remaining, for the plan-timer's bar —
     * full at the start of the period, empty at expiration.
     */
    #[Computed]
    public function fracaoPeriodo(): float
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return 0.0;
        }

        $totalDays = max(1, $subscription->current_period_start->diffInDays($subscription->current_period_end));

        return min(1.0, max(0.0, ($this->diasRestantes ?? 0) / $totalDays));
    }

    private function subscription(): ?PlanSubscription
    {
        return resolve(Subscriptions::class)->active($this->team());
    }

    private function team(): ?Team
    {
        return resolve(TenantManager::class)->current();
    }
}; ?>

<x-fire.glow class="p-6 lg:p-10">
    <livewire:pages::teams.pending-invitations-modal />

    <div class="mx-auto flex max-w-5xl flex-col gap-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-medium tracking-widest text-white/55 uppercase">{{ __('Painel') }}</p>
                <h1 class="font-display text-2xl font-semibold text-white">{{ __('Como o time está usando o checker') }}</h1>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button
                    :href="route('checker.carteira', $this->team())"
                    icon="wallet"
                    wire:navigate
                    data-test="dashboard-carteira"
                >
                    {{ __('Carteira') }}
                </flux:button>

                <flux:button
                    :href="route('checker.executar', $this->team())"
                    variant="primary"
                    icon="bolt"
                    wire:navigate
                    data-test="dashboard-executar-consulta"
                >
                    {{ __('Executar consulta') }}
                </flux:button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-fire.stat
                :label="__('Consultas')"
                :value="Number::format($this->consultasNoPeriodo, locale: 'pt_BR')"
                :hint="__('Últimos 30 dias')"
                tone="fire"
            />

            <x-fire.stat
                :label="__('Taxa de sucesso')"
                :value="$this->taxaSucesso !== null ? Number::percentage($this->taxaSucesso, precision: 1, locale: 'pt_BR') : '—'"
                :hint="$this->taxaSucesso !== null ? __('Nos últimos 30 dias') : __('Nenhuma consulta ainda')"
                :tone="$this->taxaSucesso !== null && $this->taxaSucesso >= 90 ? 'ok' : 'neutral'"
            />

            <x-fire.stat
                :label="__('Saldo')"
                :value="Number::currency($this->saldoCents / 100, in: 'BRL', locale: 'pt_BR')"
                :hint="__('Disponível para consultas')"
            />
        </div>

        <x-fire.plan-timer
            :label="__('Plano :name', ['name' => $this->billing->plan()->name])"
            :daysLeft="$this->diasRestantes"
            :fraction="$this->fracaoPeriodo"
        />

        @if ($this->consultasNoPeriodo === 0)
            <div class="rounded-xl border border-dashed border-fire-500/25 bg-white/2 p-6 text-center" data-test="dashboard-empty-state">
                <p class="font-display text-lg font-semibold text-white">{{ __('Nenhuma consulta ainda') }}</p>
                <p class="mt-1 text-sm text-white/55">{{ __('Execute a primeira consulta e acompanhe os números aqui.') }}</p>
            </div>
        @endif
    </div>
</x-fire.glow>
