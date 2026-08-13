<?php

declare(strict_types=1);

use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Subscriptions;
use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\CheckExecution;

new #[Title('Dashboard')] class extends Component {
    /**
     * The rolling window behind every number on this screen. The paid period
     * can start on any day of the month, so "recent activity" is a fixed
     * 30-day window here, independent of which plan the team is on — a
     * different question from Allowance's per-check quota, which does align
     * to the subscription cycle.
     */
    private const int WINDOW_DAYS = 30;

    /**
     * How many rows each "Últimas ..." panel shows — matches the legacy
     * dashboard's LIMIT on both its checks and transactions queries.
     */
    private const int RECENT_LIMIT = 5;

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

    /**
     * The team's most recent check executions, newest first, for the
     * "Últimas consultas" panel — team-scoped, not per-user (contrast with
     * Histórico's own list, which is filtered to the signed-in member): this
     * is the team's dashboard, and the maps-to-legacy panel showed every
     * teammate's checks.
     *
     * @return Collection<int, CheckExecution>
     */
    #[Computed]
    public function recentChecks(): Collection
    {
        return CheckExecution::query()
            ->with('check')
            ->latest()
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * The team's most recent wallet ledger lines, newest first, for the
     * "Últimas transações" panel.
     *
     * @return Collection<int, WalletTransaction>
     */
    #[Computed]
    public function recentTransactions(): Collection
    {
        return WalletTransaction::query()
            ->latest()
            ->limit(self::RECENT_LIMIT)
            ->get();
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

<x-fire.glow>
    <livewire:pages::teams.pending-invitations-modal />

    <div class="mx-auto flex max-w-5xl flex-col gap-8">
        {{-- ===== TOP-BAR ===== --}}
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-fire-500/10 pb-6">
            <div>
                <h1 class="text-fire-gradient font-display text-3xl font-bold">{{ __('Dashboard') }}</h1>
                <p class="mt-1 text-sm text-white/55">
                    {{ __('Bem-vindo de volta, :name!', ['name' => auth()->user()->name]) }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <span
                    class="inline-flex items-center gap-2 rounded-lg border border-ok/10 bg-ok/5 px-4 py-2 font-mono text-sm text-ok"
                    data-test="dashboard-saldo-badge"
                >
                    <flux:icon.banknotes class="size-4" />
                    {{ Number::currency($this->saldoCents / 100, in: 'BRL', locale: 'pt_BR') }}
                </span>

                <span
                    class="inline-flex items-center gap-2 rounded-lg border border-fire-500/10 bg-fire-500/5 px-4 py-2 font-display text-xs font-semibold tracking-widest text-fire-400 uppercase"
                    data-test="dashboard-plano-badge"
                >
                    <flux:icon.rectangle-stack class="size-4" />
                    {{ $this->billing->plan()->name }}
                </span>

                <flux:button
                    :href="route('checker.executar')"
                    variant="primary"
                    icon="bolt"
                    wire:navigate
                    data-test="dashboard-executar-consulta"
                >
                    {{ __('Executar consulta') }}
                </flux:button>
            </div>
        </div>

        {{-- ===== STATS ===== --}}
        <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-[18px]">
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

        {{-- ===== PLAN TIMER ===== --}}
        <x-fire.plan-timer
            :label="__('Plano :name', ['name' => $this->billing->plan()->name])"
            :daysLeft="$this->diasRestantes"
            :fraction="$this->fracaoPeriodo"
        />

        {{-- ===== ÚLTIMAS CONSULTAS / TRANSAÇÕES ===== --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-fire-500/14 bg-white/2 p-5" data-test="dashboard-consultas-recentes">
                <h2 class="flex items-center gap-2 font-display text-sm font-semibold tracking-widest text-white/70 uppercase">
                    <flux:icon.clock class="size-4 text-fire-500" />
                    {{ __('Últimas consultas') }}
                </h2>

                @if ($this->recentChecks->isEmpty())
                    <div class="mt-6 flex flex-col items-center gap-1 py-6 text-center text-white/40" data-test="consultas-recentes-vazio">
                        <flux:icon.inbox class="size-8" />
                        <p class="text-sm font-medium text-white/55">{{ __('Nenhuma consulta realizada ainda') }}</p>
                        <p class="text-xs">{{ __('Execute a primeira e ela aparece aqui.') }}</p>
                    </div>
                @else
                    <ul class="mt-4 divide-y divide-white/5">
                        @foreach ($this->recentChecks as $execucao)
                            @php
                                $rotuloOutcome = match ($execucao->outcome) {
                                    ProviderOutcome::Success => __('Sucesso'),
                                    ProviderOutcome::NoData => __('Sem registro'),
                                    ProviderOutcome::Failed => __('Falhou'),
                                };

                                $corOutcome = match ($execucao->outcome) {
                                    ProviderOutcome::Success => 'border-ok/30 bg-ok/10 text-ok',
                                    ProviderOutcome::NoData => 'border-white/14 bg-white/5 text-white/55',
                                    ProviderOutcome::Failed => 'border-flame-danger/30 bg-flame-danger/10 text-flame-danger',
                                };
                            @endphp

                            <li
                                wire:key="consulta-recente-{{ $execucao->id }}"
                                class="flex items-center justify-between gap-3 py-3 text-sm"
                                data-test="consulta-recente-{{ $execucao->id }}"
                            >
                                <div>
                                    <p class="font-medium text-white">{{ $execucao->check->name }}</p>
                                    <p class="text-xs text-white/40">{{ $execucao->created_at?->format('d/m H:i') }}</p>
                                </div>

                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $corOutcome }}">
                                    {{ $rotuloOutcome }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-xl border border-fire-500/14 bg-white/2 p-5" data-test="dashboard-transacoes-recentes">
                <h2 class="flex items-center gap-2 font-display text-sm font-semibold tracking-widest text-white/70 uppercase">
                    <flux:icon.banknotes class="size-4 text-fire-500" />
                    {{ __('Últimas transações') }}
                </h2>

                @if ($this->recentTransactions->isEmpty())
                    <div class="mt-6 flex flex-col items-center gap-1 py-6 text-center text-white/40" data-test="transacoes-recentes-vazio">
                        <flux:icon.inbox class="size-8" />
                        <p class="text-sm font-medium text-white/55">{{ __('Nenhuma transação realizada ainda') }}</p>
                        <p class="text-xs">{{ __('Recarregue a carteira para ver o extrato aqui.') }}</p>
                    </div>
                @else
                    <ul class="mt-4 divide-y divide-white/5">
                        @foreach ($this->recentTransactions as $lancamento)
                            @php
                                $credito = $lancamento->type->isCredit();
                                $rotuloTipo = match ($lancamento->type) {
                                    WalletTransactionType::Recharge => __('Recarga'),
                                    WalletTransactionType::Charge => __('Cobrança'),
                                    WalletTransactionType::Refund => __('Estorno'),
                                    WalletTransactionType::Bonus => __('Bônus'),
                                    WalletTransactionType::Adjustment => __('Ajuste'),
                                };
                            @endphp

                            <li
                                wire:key="transacao-recente-{{ $lancamento->id }}"
                                class="flex items-center justify-between gap-3 py-3 text-sm"
                                data-test="transacao-recente-{{ $lancamento->id }}"
                            >
                                <div>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $credito ? 'border-ok/30 bg-ok/10 text-ok' : 'border-fire-500/30 bg-fire-500/10 text-fire-400' }}">
                                        {{ $rotuloTipo }}
                                    </span>
                                    <p class="mt-1 text-xs text-white/40">{{ $lancamento->created_at?->format('d/m H:i') }}</p>
                                </div>

                                <p class="font-mono text-sm {{ $credito ? 'text-ok' : 'text-fire-400' }}">
                                    {{ $credito ? '+' : '−' }} {{ Number::currency(abs($lancamento->amount_cents) / 100, in: 'BRL', locale: 'pt_BR') }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-fire.glow>
