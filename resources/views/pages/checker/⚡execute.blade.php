<?php

declare(strict_types=1);

use App\Actions\CheckerApi\ExecuteCheck;
use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
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
                    'masked' => $this->hardMask($line),
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
     * Masks a validated document (already known to be 11 or 14 digits) the
     * same way {@see CheckInput::masked()} would mask it as an input — first
     * 3 and last 2 kept, middle starred. Never use this for unvalidated
     * input: {@see CheckInput::masked()} returns short values (under 6
     * chars) untouched, which is safe for a CPF/CNPJ digit string but would
     * leak a short *invalid* line verbatim — {@see self::hardMask()} is for
     * that case.
     */
    private function mask(string $value): string
    {
        return CheckInput::fromArray(['documento' => $value])->masked()['documento'];
    }

    /**
     * Masks an unvalidated line for display — always obscures, regardless of
     * length, unlike {@see CheckInput::masked()}. Used only for `invalid`
     * rows, where the raw text never went through the digit-length check
     * that makes the normal mask safe. Keeps at most the first character;
     * one or two characters are starred out entirely.
     */
    private function hardMask(string $value): string
    {
        $length = Str::length($value);

        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return Str::substr($value, 0, 1).str_repeat('*', $length - 1);
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
        $executionIds = collect($this->results)->pluck('execution_id')->filter()->values();

        // Uma query pro lote inteiro, não uma por linha: um lote de 100
        // não pode virar 100 SELECTs só pra montar a tela.
        $executions = $executionIds->isEmpty() ? collect() : CheckExecution::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', $executionIds)
            ->get()
            ->keyBy('id');

        return collect($this->results)->map(function (array $entry) use ($executions): array {
            $execution = $entry['execution_id'] === null ? null : $executions->get($entry['execution_id']);

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
    <div class="mx-auto flex max-w-3xl flex-col gap-10">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-medium tracking-widest text-white/55 uppercase">{{ __('Consultas') }}</p>
                <h1 class="text-fire-gradient font-display text-3xl font-bold">{{ __('Executar consulta') }}</h1>
                <p class="mt-1 text-sm text-white/55">{{ __('Escolha uma consulta, cole os documentos — um por linha — e veja o resultado de cada um na hora.') }}</p>
            </div>

            {{-- Saldo antes de gastar, não só depois: o chip fica no header e o
                 Livewire o re-renderiza junto com o lote, então ele já mostra o
                 saldo descontado assim que os resultados chegam. --}}
            <div class="inline-flex items-baseline gap-2 rounded-lg border border-fire-500/10 bg-fire-500/5 px-4 py-2">
                <span class="font-display text-xs font-semibold tracking-widest text-fire-400 uppercase">{{ __('Saldo') }}</span>
                <span class="font-mono text-sm font-semibold tabular-nums text-white" data-test="saldo-atual">{{ Number::currency($this->balanceCents / 100, in: 'BRL', locale: 'pt_BR') }}</span>
            </div>
        </div>

        <div class="space-y-3">
            {{-- Os passos são numerados porque a tela é uma sequência de verdade:
                 sem consulta escolhida não há lote, sem lote não há resultado. --}}
            <h2 class="flex items-baseline gap-2.5 font-display text-sm font-semibold tracking-widest text-white/70 uppercase">
                <span class="font-mono text-fire-400" aria-hidden="true">01</span>
                {{ __('Consulta') }}
            </h2>

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
                        class="flex flex-col rounded-xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-50
                            {{ $selected
                                ? 'border-fire-500/60 bg-gradient-to-br from-fire-500/12 to-fire-600/4 ring-1 ring-fire-500/40 shadow-[0_0_24px_-8px_rgb(255_107_0_/_50%)]'
                                : 'border-fire-500/14 bg-white/2 hover:border-fire-500/35 hover:bg-fire-500/4' }}"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-2">
                                {{-- A "brasa": ponto que acende com glow no card escolhido. --}}
                                <span aria-hidden="true" class="size-2 shrink-0 rounded-full transition {{ $selected ? 'bg-fire-500 shadow-[0_0_8px_rgb(255_107_0)]' : 'border border-white/25' }}"></span>
                                <span class="font-display font-semibold text-white">{{ $check->name }}</span>
                            </span>

                            @if (! $entry['available'])
                                <flux:badge size="sm" color="amber">{{ __('Em manutenção') }}</flux:badge>
                            @endif
                        </div>

                        @if ($check->description)
                            <p class="mt-1 text-xs text-white/55">{{ $check->description }}</p>
                        @endif

                        <p class="mt-3 border-t border-white/6 pt-2 font-mono text-sm text-fire-400">
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

        <form wire:submit="execute" class="flex flex-col gap-5">
            <div class="space-y-3">
                <h2 class="flex items-baseline gap-2.5 font-display text-sm font-semibold tracking-widest text-white/70 uppercase">
                    <span class="font-mono text-fire-400" aria-hidden="true">02</span>
                    {{ __('Documentos') }}
                </h2>

                {{-- Painel próprio em vez de flux:textarea: a faixa de cabeçalho
                     carrega o contador de linhas ao vivo, que um campo comum não
                     tem onde mostrar. O contador é só orientação (conta linhas
                     não vazias, sem deduplicar) — a validação real continua no
                     servidor, em execute(). --}}
                <div
                    x-data="{ lines: 0, count(value) { return value.split(/\r\n|\r|\n/).filter((line) => line.trim() !== '').length } }"
                    class="rounded-xl border border-fire-500/14 bg-white/2 transition focus-within:border-fire-500/40 focus-within:ring-1 focus-within:ring-fire-500/30"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-fire-500/14 px-4 py-2">
                        <label for="documents-input" class="text-xs font-medium tracking-widest text-white/55 uppercase">{{ __('Um por linha') }}</label>
                        <span
                            class="font-mono text-xs tabular-nums"
                            :class="lines > 100 ? 'text-flame-warn' : 'text-white/45'"
                            aria-hidden="true"
                            x-text="`${lines} / 100`"
                        ></span>
                    </div>

                    <textarea
                        id="documents-input"
                        wire:model="documents"
                        x-init="lines = count($el.value)"
                        x-on:input="lines = count($el.value)"
                        placeholder="{{ "000.000.000-00\n00.000.000/0000-00" }}"
                        autocomplete="off"
                        spellcheck="false"
                        data-test="documentos-input"
                        class="min-h-[160px] w-full resize-y bg-transparent px-4 py-3 font-mono text-sm tracking-wide text-white placeholder:text-white/25 focus:outline-none"
                    ></textarea>
                </div>

                {{-- tracking-wide compensa o Rajdhani, condensado demais nesse corpo. --}}
                <p class="text-xs tracking-wide text-white/40">{{ __('CPF ou CNPJ, um por linha · até :n por lote.', ['n' => 100]) }}</p>

                @error('documents')
                    <p class="text-sm text-flame-danger" data-test="erro-documents">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                <x-fire.cta
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="execute"
                    data-test="executar-consulta"
                >
                    <flux:icon.bolt wire:loading.remove wire:target="execute" class="size-4" />
                    <flux:icon.loading wire:loading wire:target="execute" class="size-4" />
                    <span wire:loading.remove wire:target="execute">{{ __('Executar consultas') }}</span>
                    <span wire:loading wire:target="execute">{{ __('Executando…') }}</span>
                </x-fire.cta>

                <p class="text-xs tracking-wide text-white/40" data-test="lgpd-note">
                    {{ __('Os documentos são usados só para estas consultas e guardados mascarados.') }}
                </p>
            </div>
        </form>

        @if (count($this->results) > 0)
            <div wire:key="lote-resultados" class="space-y-4 motion-safe:animate-ignite" data-test="resultado-lote">
                <h2 class="font-display text-sm font-semibold tracking-widest text-white/70 uppercase">{{ __('Resultados') }}</h2>

                {{-- A barra "checker-stats" do checker legado: resumo em linha,
                     não um grid de cartões — o lote pode ter uma linha ou cem. --}}
                <div class="space-y-4" data-test="resumo-lote">
                    <div class="flex flex-wrap gap-x-8 gap-y-3">
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

                    {{-- A barra de composição do lote: cada segmento cresce
                         proporcionalmente ao seu desfecho (flex-grow = contagem,
                         sem aritmética de porcentagem) e acende em sequência. --}}
                    @php
                        $segments = collect([
                            ['count' => $this->summary['success'], 'class' => 'bg-ok'],
                            ['count' => $this->summary['no_data'], 'class' => 'bg-white/30'],
                            ['count' => $this->summary['failed'], 'class' => 'bg-flame-danger'],
                            ['count' => $this->summary['other'], 'class' => 'bg-flame-warn'],
                        ])->filter(fn (array $segment): bool => $segment['count'] > 0)->values();
                    @endphp
                    <div
                        class="flex h-1.5 gap-0.5 overflow-hidden rounded-full bg-white/6"
                        role="img"
                        aria-label="{{ __('Composição do lote: :success sucesso, :noData sem registro, :failed falha, :other não executadas.', ['success' => $this->summary['success'], 'noData' => $this->summary['no_data'], 'failed' => $this->summary['failed'], 'other' => $this->summary['other']]) }}"
                    >
                        @foreach ($segments as $index => $segment)
                            <div
                                class="{{ $segment['class'] }} motion-safe:animate-fuse"
                                style="flex-grow: {{ $segment['count'] }}; flex-basis: 0; animation-delay: {{ $index * 90 }}ms"
                            ></div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-xl border border-fire-500/14 bg-white/2 overflow-hidden" data-test="lista-resultados">
                    @foreach ($this->rows as $index => $row)
                        @php
                            [$outcomeLabel, $outcomeColor, $outcomeAccent] = match ($row['status']) {
                                'success' => [__('Sucesso'), 'border-ok/30 bg-ok/10 text-ok', 'border-l-ok/60'],
                                'no_data' => [__('Sem registro'), 'border-white/14 bg-white/5 text-white/55', 'border-l-white/20'],
                                'failed' => [__('Falha'), 'border-flame-danger/30 bg-flame-danger/10 text-flame-danger', 'border-l-flame-danger/60'],
                                'invalid' => [__('Inválido'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn', 'border-l-flame-warn/60'],
                                'blocked' => [__('Bloqueado'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn', 'border-l-flame-warn/60'],
                                'insufficient' => [__('Saldo insuficiente'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn', 'border-l-flame-warn/60'],
                                'allowance_exhausted' => [__('Franquia esgotada'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn', 'border-l-flame-warn/60'],
                                'unavailable' => [__('Em manutenção'), 'border-flame-warn/30 bg-flame-warn/10 text-flame-warn', 'border-l-flame-warn/60'],
                                default => [$row['status'], 'border-white/14 bg-white/5 text-white/55', 'border-l-white/20'],
                            };
                        @endphp

                        <div
                            wire:key="linha-{{ $index }}-{{ $row['masked'] }}"
                            class="border-l-2 p-4 transition hover:bg-white/3 {{ $outcomeAccent }} {{ ! $loop->last ? 'border-b border-b-fire-500/14' : '' }}"
                            data-test="linha-resultado-{{ $index }}"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-mono text-sm text-white" data-test="linha-documento-{{ $index }}">{{ $row['masked'] }}</span>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $outcomeColor }}">
                                    {{ $outcomeLabel }}
                                </span>
                            </div>

                            @if ($row['status'] === 'success' && $row['result'])
                                <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                    @foreach ($row['result'] as $field => $value)
                                        <div>
                                            <dt class="text-[0.65rem] font-medium tracking-widest text-white/45 uppercase">{{ str_replace('_', ' ', (string) $field) }}</dt>
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
            </div>
        @endif
    </div>
</x-fire.glow>
