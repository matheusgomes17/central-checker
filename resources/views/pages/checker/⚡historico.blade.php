<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\CheckExecution;

new #[Title('Histórico de consultas')] class extends Component {
    use WithPagination;

    /**
     * The execution open in the result panel. Set by {@see self::ver()},
     * but the property itself protects nothing — {@see self::selected()}
     * re-filters by user_id on every read, so a same-team teammate's id
     * resolves to null even with this set to it.
     */
    #[Locked]
    public ?int $selectedId = null;

    public function mount(): void
    {
        abort_unless($this->team() !== null, 404);
    }

    public function ver(int $id): void
    {
        $this->selectedId = $id;
    }

    public function fechar(): void
    {
        $this->selectedId = null;
    }

    /**
     * The signed-in user's own executions, newest first. The tenant scope
     * already confines reads to the team; this adds the per-person filter on
     * top, because a team can have several members and "team-scoped" is not
     * "mine". Never acrossTenants — that would defeat both filters at once.
     *
     * @return LengthAwarePaginator<int, CheckExecution>
     */
    #[Computed]
    public function execucoes(): LengthAwarePaginator
    {
        return CheckExecution::query()
            ->where('user_id', Auth::id())
            ->with('check')
            ->latest()
            ->paginate(15);
    }

    #[Computed]
    public function totalExecucoes(): int
    {
        return CheckExecution::query()->where('user_id', Auth::id())->count();
    }

    /**
     * The open execution, scoped to its owner. This where('user_id', ...) —
     * not the #[Locked] attribute above — is the actual guard: it is what
     * keeps a same-team teammate's crafted `ver($id)` call from resolving to
     * anyone else's (encrypted, third-party PII) result.
     */
    #[Computed]
    public function selected(): ?CheckExecution
    {
        if ($this->selectedId === null) {
            return null;
        }

        return CheckExecution::query()
            ->where('user_id', Auth::id())
            ->find($this->selectedId);
    }

    private function team(): ?Team
    {
        return resolve(TenantManager::class)->current();
    }
}; ?>

<x-fire.glow class="p-6 lg:p-10">
    <div class="mx-auto flex max-w-3xl flex-col gap-8">
        <div>
            <p class="text-xs font-medium tracking-widest text-white/55 uppercase">{{ __('Consultas') }}</p>
            <h1 class="font-display text-2xl font-semibold text-white">{{ __('Histórico de consultas') }}</h1>
            <p class="mt-1 text-sm text-white/55">{{ __('Suas consultas, com a entrada sempre mascarada. Só você vê o resultado de cada uma.') }}</p>
        </div>

        @if ($this->totalExecucoes > 0)
            <x-fire.stat
                :label="__('Consultas executadas')"
                :value="Number::format($this->totalExecucoes, locale: 'pt_BR')"
                :hint="__('No total, por você')"
            />
        @endif

        @if ($this->execucoes->isEmpty())
            <div class="rounded-xl border border-dashed border-fire-500/25 bg-white/2 p-6 text-center" data-test="historico-vazio">
                <p class="font-display text-lg font-semibold text-white">{{ __('você ainda não rodou consultas') }}</p>
                <p class="mt-1 text-sm text-white/55">{{ __('Execute a primeira e ela aparece aqui, com o resultado guardado só para você.') }}</p>

                <flux:button
                    :href="route('checker.executar', $this->team())"
                    variant="primary"
                    icon="bolt"
                    wire:navigate
                    class="mt-4"
                    data-test="historico-comecar-agora"
                >
                    {{ __('Comece agora') }}
                </flux:button>
            </div>
        @else
            <div class="space-y-3">
                <div class="rounded-xl border border-fire-500/14 bg-white/2 overflow-hidden">
                    @foreach ($this->execucoes as $execucao)
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

                            $entradaMascarada = collect($execucao->input_masked)
                                ->map(fn (string $valor, string $campo): string => "{$campo}: {$valor}")
                                ->implode(', ');
                        @endphp

                        <div
                            wire:key="execucao-{{ $execucao->id }}"
                            class="flex flex-wrap items-center justify-between gap-4 p-4 {{ ! $loop->last ? 'border-b border-fire-500/14' : '' }}"
                            data-test="execucao-{{ $execucao->id }}"
                        >
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-display font-semibold text-white">{{ $execucao->check->name }}</span>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $corOutcome }}">
                                        {{ $rotuloOutcome }}
                                    </span>
                                </div>
                                <p class="font-mono text-xs text-white/55">{{ $entradaMascarada }}</p>
                                <p class="text-xs text-white/40">{{ $execucao->created_at?->format('d/m/Y H:i') }}</p>
                            </div>

                            @if ($execucao->outcome === ProviderOutcome::Success)
                                <button
                                    type="button"
                                    wire:click="ver({{ $execucao->id }})"
                                    data-test="ver-resultado-{{ $execucao->id }}"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-fire-500/25 px-3 py-1.5 text-xs font-medium text-white/80 transition hover:border-fire-500/50 hover:text-white"
                                >
                                    <flux:icon.eye class="size-3.5" />
                                    {{ __('Ver resultado') }}
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div data-test="historico-paginacao">
                    {{ $this->execucoes->links() }}
                </div>
            </div>
        @endif

        @if ($this->selected)
            @php
                $entradaSelecionada = collect($this->selected->input_masked)
                    ->map(fn (string $valor, string $campo): string => "{$campo}: {$valor}")
                    ->implode(', ');
            @endphp

            <div
                wire:key="resultado-{{ $this->selected->id }}"
                class="rounded-xl border border-fire-500/25 bg-white/2 p-6 motion-safe:animate-ignite"
                data-test="resultado-selecionado"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-1.5 text-xs font-medium tracking-widest text-ok uppercase">
                        <flux:icon.check-circle class="size-3.5 shrink-0" />
                        <span>{{ $this->selected->check->name }}</span>
                    </div>

                    <button
                        type="button"
                        wire:click="fechar"
                        data-test="fechar-resultado"
                        class="text-xs text-white/55 transition hover:text-white"
                    >
                        {{ __('Fechar') }}
                    </button>
                </div>

                <p class="mt-2 font-mono text-xs text-white/55" data-test="entrada-selecionada">{{ $entradaSelecionada }}</p>

                <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                    @foreach ($this->selected->result ?? [] as $campo => $valor)
                        <div>
                            <dt class="text-xs text-white/55 capitalize">{{ str_replace('_', ' ', (string) $campo) }}</dt>
                            <dd class="font-mono text-sm text-white">{{ is_scalar($valor) ? $valor : json_encode($valor) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </div>
</x-fire.glow>
