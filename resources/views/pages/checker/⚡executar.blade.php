<?php

declare(strict_types=1);

use App\Actions\CheckerApi\ExecutarCheck;
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
use Modules\CheckerApi\Exceptions\CheckBlockedForUserException;
use Modules\CheckerApi\Exceptions\CheckUnavailableException;
use Modules\CheckerApi\Exceptions\FranquiaExhaustedException;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\CheckAvailability;
use Modules\CheckerApi\Support\CheckInput;

new #[Title('Executar consulta')] class extends Component {
    public ?int $checkId = null;

    public string $documento = '';

    /**
     * Set only server-side, inside {@see self::executar()}, once a run
     * succeeds. Locked so a crafted Livewire request can't swap it for a
     * sibling team member's execution id and read their (decrypted) result.
     */
    #[Locked]
    public ?int $executionId = null;

    public function mount(): void
    {
        abort_unless($this->team() !== null, 404);
    }

    /**
     * Validates, then runs the selected check, translating every domain
     * exception {@see ExecutarCheck::handle()} can throw into a friendly
     * field error — the raw exception message never reaches the screen.
     */
    public function executar(): void
    {
        $this->executionId = null;

        $this->validate([
            'checkId' => ['required', 'integer'],
            'documento' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                $digitos = preg_replace('/\D/', '', (string) $value) ?? '';

                // Só dígitos + comprimento (11 = CPF, 14 = CNPJ). Nenhum
                // provedor real existe ainda para exigir o dígito verificador
                // — adicionar aquele validador agora seria complexidade sem
                // uso (YAGNI); fica para quando um provedor de verdade
                // precisar da validação completa.
                if (! in_array(strlen($digitos), [11, 14], true)) {
                    $fail(__('Informe um CPF (11 dígitos) ou CNPJ (14 dígitos).'));
                }
            }],
        ], [
            'checkId.required' => __('Escolha uma consulta.'),
        ]);

        $check = Check::query()->find($this->checkId);

        if (! $check instanceof Check || ! resolve(CheckAvailability::class)->available($check)) {
            $this->addError('checkId', __('Esta consulta está indisponível no momento.'));

            return;
        }

        $digitos = preg_replace('/\D/', '', $this->documento) ?? '';
        $input = CheckInput::fromArray(['documento' => $digitos]);

        try {
            $execution = resolve(ExecutarCheck::class)->handle($check, $input, Auth::user());
        } catch (CheckUnavailableException) {
            $this->addError('checkId', __('Esta consulta está em manutenção no momento. Tente de novo mais tarde.'));

            return;
        } catch (FranquiaExhaustedException) {
            $this->addError('checkId', __('A franquia do plano para esta consulta acabou e ela não aceita cobrança avulsa. Fale com o administrador do time sobre o plano.'));

            return;
        } catch (CheckBlockedForUserException) {
            $this->addError('checkId', __('Você está bloqueado para executar esta consulta. Fale com o administrador do time.'));

            return;
        } catch (InsufficientBalanceException) {
            $this->addError('checkId', __('Saldo insuficiente para esta consulta — recarregue a carteira e tente de novo.'));

            return;
        }

        $this->executionId = $execution->id;
    }

    /**
     * Active or maintenance checks in the catalog, each paired with whether
     * it may run right now. Maintenance and breaker-open checks stay in the
     * list instead of disappearing — the state must stay visible, not hidden.
     *
     * @return Collection<int, array{check: Check, available: bool}>
     */
    #[Computed]
    public function consultas(): Collection
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

    #[Computed]
    public function execution(): ?CheckExecution
    {
        if ($this->executionId === null) {
            return null;
        }

        // Escopado pelo dono, não só pelo time: o id é sequencial e
        // previsível, então mesmo travado por #[Locked] a consulta precisa
        // recusar sozinha a execução de um colega de time.
        return CheckExecution::query()
            ->where('user_id', Auth::id())
            ->find($this->executionId);
    }

    /**
     * What the run cost, in words: covered by the plan's franquia, free
     * because the check has no price, the amount charged to the wallet, or
     * "not charged" for the two outcomes that never bill.
     */
    #[Computed]
    public function custoLabel(): ?string
    {
        $execution = $this->execution;

        if ($execution === null) {
            return null;
        }

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

    #[Computed]
    public function saldoCents(): int
    {
        return Wallet::forTeam($this->team())->balance_cents;
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
            <p class="mt-1 text-sm text-white/55">{{ __('Escolha uma consulta, informe o documento e veja o resultado na hora.') }}</p>
        </div>

        <div class="space-y-3">
            <flux:heading size="sm">{{ __('Consulta') }}</flux:heading>

            <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="{{ __('Escolha a consulta') }}">
                @foreach ($this->consultas as $entry)
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

                @if ($this->consultas->isEmpty())
                    <p class="text-sm text-white/55" data-test="sem-consultas">{{ __('Nenhuma consulta disponível no momento.') }}</p>
                @endif
            </div>

            @error('checkId')
                <p class="text-sm text-flame-danger" data-test="erro-checkId">{{ $message }}</p>
            @enderror
        </div>

        <form wire:submit="executar" class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <div class="flex-1">
                <flux:input
                    wire:model="documento"
                    :label="__('Documento (CPF ou CNPJ)')"
                    placeholder="000.000.000-00"
                    class:input="font-mono tracking-widest"
                    autocomplete="off"
                    data-test="documento-input"
                />
            </div>

            {{-- O botão da tela-assinatura: gradiente de fogo próprio, em vez do
                 variant="primary" plano usado no resto do produto. --}}
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="executar"
                data-test="executar-consulta"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-fire-400 via-fire-500 to-fire-600 px-5 font-display text-sm font-semibold text-white shadow-[0_0_20px_-4px_rgb(255_107_0_/_60%)] transition motion-safe:hover:scale-[1.01] motion-safe:active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60"
            >
                <flux:icon.bolt wire:loading.remove wire:target="executar" class="size-4" />
                <flux:icon.loading wire:loading wire:target="executar" class="size-4" />
                <span wire:loading.remove wire:target="executar">{{ __('Executar consulta') }}</span>
                <span wire:loading wire:target="executar">{{ __('Executando…') }}</span>
            </button>
        </form>

        <p class="text-xs text-white/40" data-test="lgpd-note">
            {{ __('O documento é usado só para esta consulta e guardado mascarado.') }}
        </p>

        @if ($this->execution)
            <div
                wire:key="resultado-{{ $this->execution->id }}"
                class="rounded-xl border border-fire-500/25 bg-white/2 p-6 motion-safe:animate-ignite"
                data-test="resultado-execucao"
            >
                @if ($this->execution->outcome === ProviderOutcome::Success)
                    <div class="flex items-center gap-1.5 text-xs font-medium tracking-widest text-ok uppercase">
                        <flux:icon.check-circle class="size-3.5 shrink-0" />
                        <span>{{ __('Resultado encontrado') }}</span>
                    </div>

                    <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        @foreach ($this->execution->result ?? [] as $campo => $valor)
                            <div>
                                <dt class="text-xs text-white/55 capitalize">{{ str_replace('_', ' ', (string) $campo) }}</dt>
                                <dd class="font-mono text-sm text-white">{{ is_scalar($valor) ? $valor : json_encode($valor) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @elseif ($this->execution->outcome === ProviderOutcome::NoData)
                    <div class="flex items-center gap-1.5 text-xs font-medium tracking-widest text-white/55 uppercase">
                        <flux:icon.magnifying-glass class="size-3.5 shrink-0" />
                        <span>{{ __('nenhum registro encontrado') }}</span>
                    </div>
                    <p class="mt-1 text-sm text-white/55">{{ __('Não há registro para este documento nesta consulta.') }}</p>
                @else
                    <div class="flex items-center gap-1.5 text-xs font-medium tracking-widest text-flame-warn uppercase">
                        <flux:icon.exclamation-triangle class="size-3.5 shrink-0" />
                        <span>{{ __('a consulta não pôde ser concluída') }}</span>
                    </div>
                    <p class="mt-1 text-sm text-white/55">{{ __('Tente de novo em instantes. Se continuar, fale com o suporte.') }}</p>
                @endif

                <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 border-t border-fire-500/14 pt-4 text-sm">
                    <p><span class="text-white/55">{{ __('Custo:') }}</span> <span class="font-mono text-white" data-test="custo-execucao">{{ $this->custoLabel }}</span></p>
                    <p><span class="text-white/55">{{ __('Saldo atual:') }}</span> <span class="font-mono text-white" data-test="saldo-atual">{{ Number::currency($this->saldoCents / 100, in: 'BRL', locale: 'pt_BR') }}</span></p>
                </div>
            </div>
        @endif
    </div>
</x-fire.glow>
