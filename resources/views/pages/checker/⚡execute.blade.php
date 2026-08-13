<?php

declare(strict_types=1);

use App\Actions\CheckerApi\ExecuteCheck;
use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Models\Wallet;
use Modules\CheckerApi\Enums\CheckStatus;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Exceptions\AllowanceExhaustedException;
use Modules\CheckerApi\Exceptions\CheckBlockedForUserException;
use Modules\CheckerApi\Exceptions\CheckUnavailableException;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\CheckAvailability;
use Modules\CheckerApi\Support\CheckInput;

new #[Title('Executar consulta')] class extends Component {
    /**
     * Above this many unique lines in one batch, {@see self::execute()}
     * refuses instead of silently truncating — a hard cap keeps one paste
     * from turning into an unbounded loop of provider calls and holds.
     */
    private const int MAX_BATCH = 100;

    public ?int $checkId = null;

    public string $documents = '';

    /**
     * One entry per unique, non-blank line submitted, in submission order.
     * Set only server-side, inside {@see self::execute()}. Locked so a
     * crafted Livewire request can't inject or swap entries and point
     * `execution_id` at a sibling team member's execution to read their
     * (decrypted) result via {@see self::rows()}.
     *
     * @var list<array{masked: string, status: string, execution_id: ?int}>
     */
    #[Locked]
    public array $results = [];

    public function mount(): void
    {
        abort_unless($this->team() !== null, 404);
    }

    /**
     * Validates the check, parses the pasted lines, then runs one check per
     * unique document. Every domain exception {@see ExecuteCheck::handle()}
     * can throw is caught per line — one bad line (invalid format, no
     * balance, blocked user, exhausted allowance, check gone into
     * maintenance mid-batch) marks that line and moves on, it never aborts
     * the rest of the batch.
     */
    public function execute(): void
    {
        $this->results = [];

        $this->validate([
            'checkId' => ['required', 'integer'],
        ], [
            'checkId.required' => __('Escolha uma consulta.'),
        ]);

        $check = Check::query()->find($this->checkId);

        if (! $check instanceof Check || ! resolve(CheckAvailability::class)->available($check)) {
            $this->addError('checkId', __('Esta consulta está indisponível no momento.'));

            return;
        }

        $lines = collect(preg_split('/\r\n|\r|\n/', $this->documents) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->unique()
            ->values();

        if ($lines->isEmpty()) {
            $this->addError('documents', __('Informe ao menos um documento (um por linha).'));

            return;
        }

        if ($lines->count() > self::MAX_BATCH) {
            $this->addError('documents', __('No máximo :n documentos por vez.', ['n' => self::MAX_BATCH]));

            return;
        }

        $results = [];

        foreach ($lines as $line) {
            // Só dígitos + comprimento (11 = CPF, 14 = CNPJ). Nenhum
            // provedor real existe ainda para exigir o dígito verificador —
            // adicionar aquele validador agora seria complexidade sem uso
            // (YAGNI); fica para quando um provedor de verdade precisar da
            // validação completa.
            $digits = preg_replace('/\D/', '', $line) ?? '';

            if (! in_array(strlen($digits), [11, 14], true)) {
                $results[] = [
                    'masked' => $this->mask($line),
                    'status' => 'invalid',
                    'execution_id' => null,
                ];

                continue;
            }

            $input = CheckInput::fromArray(['documento' => $digits]);

            try {
                $execution = resolve(ExecuteCheck::class)->handle($check, $input, Auth::user());

                $results[] = [
                    'masked' => $this->mask($digits),
                    'status' => $execution->outcome->value,
                    'execution_id' => $execution->id,
                ];
            } catch (CheckUnavailableException) {
                $results[] = ['masked' => $this->mask($digits), 'status' => 'unavailable', 'execution_id' => null];
            } catch (AllowanceExhaustedException) {
                $results[] = ['masked' => $this->mask($digits), 'status' => 'allowance_exhausted', 'execution_id' => null];
            } catch (CheckBlockedForUserException) {
                $results[] = ['masked' => $this->mask($digits), 'status' => 'blocked', 'execution_id' => null];
            } catch (InsufficientBalanceException) {
                $results[] = ['masked' => $this->mask($digits), 'status' => 'insufficient', 'execution_id' => null];
            }
        }

        $this->results = $results;
    }

    /**
     * Masks a document value the same way {@see CheckInput::masked()} would
     * mask it as an input — first 3 and last 2 kept, middle starred. Never
     * store or render the raw value, valid or not.
     */
    private function mask(string $value): string
    {
        return CheckInput::fromArray(['documento' => $value])->masked()['documento'];
    }

    /**
     * Active or maintenance checks in the catalog, each paired with whether
     * it may run right now. Maintenance and breaker-open checks stay in the
     * list instead of disappearing — the state must stay visible, not hidden.
     *
     * @return Collection<int, array{check: Check, available: bool}>
     */
    #[Computed]
    public function checks(): Collection
    {
        $availability = resolve(CheckAvailability::class);

        return Check::query()
            ->whereIn('status', [CheckStatus::Active->value, CheckStatus::Maintenance->value])
            ->orderBy('name')
            ->get()
            ->map(fn (Check $check): array => [
                'check' => $check,
                'available' => $availability->available($check),
            ]);
    }

    /**
     * Render-ready rows, one per submitted line, in submission order. Each
     * entry with an execution_id is resolved scoped to its owner — not just
     * to the team — because the id is sequential and predictable, so even
     * behind #[Locked] the lookup must refuse a sibling team member's
     * execution on its own.
     *
     * @return Collection<int, array{masked: string, status: string, result: ?array<string, mixed>, cost: ?string}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return collect($this->results)->map(function (array $entry): array {
            $execution = $entry['execution_id'] === null ? null : CheckExecution::query()
                ->where('user_id', Auth::id())
                ->find($entry['execution_id']);

            return [
                'masked' => $entry['masked'],
                'status' => $entry['status'],
                'result' => $execution?->result,
                'cost' => $execution instanceof CheckExecution ? $this->costLabelFor($execution) : null,
            ];
        });
    }

    /**
     * Counts behind the batch's summary stats row: how many lines landed in
     * each outcome, plus a catch-all for every status that never ran a
     * provider call (invalid input, blocked user, exhausted allowance,
     * insufficient balance, check unavailable).
     *
     * @return array{total: int, success: int, no_data: int, failed: int, other: int}
     */
    #[Computed]
    public function summary(): array
    {
        $counts = ['total' => count($this->results), 'success' => 0, 'no_data' => 0, 'failed' => 0, 'other' => 0];

        foreach ($this->results as $entry) {
            match ($entry['status']) {
                'success' => $counts['success']++,
                'no_data' => $counts['no_data']++,
                'failed' => $counts['failed']++,
                default => $counts['other']++,
            };
        }

        return $counts;
    }

    #[Computed]
    public function balanceCents(): int
    {
        return Wallet::forTeam($this->team())->balance_cents;
    }

    /**
     * What the run cost, in words: covered by the plan's allowance, free
     * because the check has no price, the amount charged to the wallet, or
     * "not charged" for the two outcomes that never bill.
     */
    private function costLabelFor(CheckExecution $execution): string
    {
        if ($execution->outcome !== ProviderOutcome::Success) {
            return __('Não cobrado');
        }

        if ($execution->consumed_franquia) {
            return __('Coberto pela franquia do plano');
        }

        $price = $execution->check->price_cents;

        if ($price === 0) {
            return __('Grátis');
        }

        return Number::currency($price / 100, in: 'BRL', locale: 'pt_BR');
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
            <h1 class="font-display text-2xl font-semibold text-white">{{ __('Executar consulta') }}</h1>
            <p class="mt-1 text-sm text-white/55">{{ __('Escolha uma consulta, cole os documentos — um por linha — e veja o resultado de cada um na hora.') }}</p>
        </div>

        <div class="space-y-3">
            <flux:heading size="sm">{{ __('Consulta') }}</flux:heading>

            <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="{{ __('Escolha a consulta') }}">
                @foreach ($this->checks as $entry)
                    @php
                        $check = $entry['check'];
                        $selected = $checkId === $check->id;
                    @endphp
                    <button
                        type="button"
                        wire:click="$set('checkId', {{ $check->id }})"
                        @disabled(! $entry['available'])
                        role="radio"
                        aria-checked="{{ $selected ? 'true' : 'false' }}"
                        data-test="consulta-{{ $check->slug }}"
                        {{-- Card, não flux:select: com a franquia/manutenção visíveis por
                             item, um select nativo não teria onde pôr o badge. --}}
                        class="rounded-xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-50
                            {{ $selected ? 'border-fire-500/60 bg-fire-500/8 ring-1 ring-fire-500/40' : 'border-fire-500/14 bg-white/2 hover:border-fire-500/30' }}"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-display font-semibold text-white">{{ $check->name }}</span>

                            @if (! $entry['available'])
                                <flux:badge size="sm" color="amber">{{ __('Em manutenção') }}</flux:badge>
                            @endif
                        </div>

                        @if ($check->description)
                            <p class="mt-1 text-xs text-white/55">{{ $check->description }}</p>
                        @endif

                        <p class="mt-2 font-mono text-sm text-fire-400">
                            {{ $check->price_cents > 0 ? Number::currency($check->price_cents / 100, in: 'BRL', locale: 'pt_BR') : __('Grátis') }}
                        </p>
                    </button>
                @endforeach

                @if ($this->checks->isEmpty())
                    <p class="text-sm text-white/55" data-test="sem-consultas">{{ __('Nenhuma consulta disponível no momento.') }}</p>
                @endif
            </div>

            @error('checkId')
                <p class="text-sm text-flame-danger" data-test="erro-checkId">{{ $message }}</p>
            @enderror
        </div>

        <form wire:submit="execute" class="flex flex-col gap-4">
            <div>
                <flux:textarea
                    wire:model="documents"
                    :label="__('Documentos (um por linha)')"
                    :description="__('CPF ou CNPJ, um por linha · até :n por lote.', ['n' => 100])"
                    placeholder="{{ "000.000.000-00\n00.000.000/0000-00" }}"
                    class="min-h-[150px] font-mono text-sm tracking-wide"
                    autocomplete="off"
                    data-test="documentos-input"
                />

                @error('documents')
                    <p class="mt-1.5 text-sm text-flame-danger" data-test="erro-documents">{{ $message }}</p>
                @enderror
            </div>

            {{-- O botão da tela-assinatura: gradiente de fogo próprio, em vez do
                 variant="primary" plano usado no resto do produto. --}}
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="execute"
                data-test="executar-consulta"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-gradient-to-r from-fire-400 via-fire-500 to-fire-600 px-5 font-display text-sm font-semibold text-white shadow-[0_0_20px_-4px_rgb(255_107_0_/_60%)] transition motion-safe:hover:scale-[1.01] motion-safe:active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60"
            >
                <flux:icon.bolt wire:loading.remove wire:target="execute" class="size-4" />
                <flux:icon.loading wire:loading wire:target="execute" class="size-4" />
                <span wire:loading.remove wire:target="execute">{{ __('Executar consultas') }}</span>
                <span wire:loading wire:target="execute">{{ __('Executando…') }}</span>
            </button>
        </form>

        <p class="text-xs text-white/40" data-test="lgpd-note">
            {{ __('Os documentos são usados só para estas consultas e guardados mascarados.') }}
        </p>

        @if (count($this->results) > 0)
            <div wire:key="lote-resultados" class="space-y-4 motion-safe:animate-ignite" data-test="resultado-lote">
                <flux:heading size="sm">{{ __('Resultados') }}</flux:heading>

                {{-- A barra "checker-stats" do checker legado: resumo em linha,
                     não um grid de cartões — o lote pode ter uma linha ou cem. --}}
                <div class="flex flex-wrap gap-x-8 gap-y-3 border-b border-fire-500/14 pb-4" data-test="resumo-lote">
                    <div class="text-center">
                        <span class="block font-display text-2xl font-bold tabular-nums text-white">{{ $this->summary['total'] }}</span>
                        <span class="text-[0.65rem] font-medium tracking-widest text-white/55 uppercase">{{ __('Total') }}</span>
                    </div>
                    <div class="text-center">
                        <span class="block font-display text-2xl font-bold tabular-nums text-ok">{{ $this->summary['success'] }}</span>
                        <span class="text-[0.65rem] font-medium tracking-widest text-white/55 uppercase">{{ __('Sucesso') }}</span>
                    </div>
                    <div class="text-center">
                        <span class="block font-display text-2xl font-bold tabular-nums text-white/70">{{ $this->summary['no_data'] }}</span>
                        <span class="text-[0.65rem] font-medium tracking-widest text-white/55 uppercase">{{ __('Sem registro') }}</span>
                    </div>
                    <div class="text-center">
                        <span class="block font-display text-2xl font-bold tabular-nums text-flame-danger">{{ $this->summary['failed'] }}</span>
                        <span class="text-[0.65rem] font-medium tracking-widest text-white/55 uppercase">{{ __('Falha') }}</span>
                    </div>
                    <div class="text-center">
                        <span class="block font-display text-2xl font-bold tabular-nums text-flame-warn">{{ $this->summary['other'] }}</span>
                        <span class="text-[0.65rem] font-medium tracking-widest text-white/55 uppercase">{{ __('Não executadas') }}</span>
                    </div>
                </div>

                <div class="rounded-xl border border-fire-500/14 bg-white/2 overflow-hidden" data-test="lista-resultados">
                    @foreach ($this->rows as $index => $row)
                        @php
                            [$outcomeLabel, $outcomeColor] = match ($row['status']) {
                                'success' => [__('Sucesso'), 'border-ok/30 bg-ok/10 text-ok'],
                                'no_data' => [__('Sem registro'), 'border-white/14 bg-white/5 text-white/55'],
                                'failed' => [__('Falha'), 'border-flame-danger/30 bg-flame-danger/10 text-flame-danger'],
                                'invalid' => [__('Inválido'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn'],
                                'blocked' => [__('Bloqueado'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn'],
                                'insufficient' => [__('Saldo insuficiente'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn'],
                                'allowance_exhausted' => [__('Franquia esgotada'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn'],
                                'unavailable' => [__('Em manutenção'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn'],
                                default => [$row['status'], 'border-white/14 bg-white/5 text-white/55'],
                            };
                        @endphp

                        <div
                            wire:key="linha-{{ $index }}-{{ $row['masked'] }}"
                            class="p-4 {{ ! $loop->last ? 'border-b border-fire-500/14' : '' }}"
                            data-test="linha-resultado-{{ $index }}"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-mono text-sm text-white" data-test="linha-documento-{{ $index }}">{{ $row['masked'] }}</span>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $outcomeColor }}">
                                    {{ $outcomeLabel }}
                                </span>
                            </div>

                            @if ($row['status'] === 'success' && $row['result'])
                                <dl class="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                                    @foreach ($row['result'] as $field => $value)
                                        <div>
                                            <dt class="text-xs text-white/55 capitalize">{{ str_replace('_', ' ', (string) $field) }}</dt>
                                            <dd class="font-mono text-sm text-white">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @elseif ($row['status'] === 'no_data')
                                <p class="mt-1 text-xs text-white/55">{{ __('Não há registro para este documento nesta consulta.') }}</p>
                            @elseif ($row['status'] === 'failed')
                                <p class="mt-1 text-xs text-white/55">{{ __('A consulta não pôde ser concluída para este documento.') }}</p>
                            @endif

                            @if ($row['cost'])
                                <p class="mt-2 text-xs text-white/55">
                                    {{ __('Custo:') }} <span class="font-mono text-white">{{ $row['cost'] }}</span>
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <p class="text-sm">
                    <span class="text-white/55">{{ __('Saldo atual:') }}</span>
                    <span class="font-mono text-white" data-test="saldo-atual">{{ Number::currency($this->balanceCents / 100, in: 'BRL', locale: 'pt_BR') }}</span>
                </p>
            </div>
        @endif
    </div>
</x-fire.glow>
