<?php

declare(strict_types=1);

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Support\TenantManager;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Payments\Actions\CreateRecharge;
use Modules\Payments\Enums\PaymentPurpose;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Exceptions\RechargeAmountException;
use Modules\Payments\Models\PaymentIntent;
use Modules\Payments\Support\PayerData;

new #[Title('Carteira')] class extends Component {
    public string $amount = '';

    /**
     * Id of the PaymentIntent this recharge opened, set only server-side
     * inside {@see self::recharge()}. Locked so a crafted Livewire request
     * can't swap it for another team's intent id — the intent itself is
     * team-scoped, but the id alone shouldn't be attacker-controlled.
     */
    #[Locked]
    public ?int $intentId = null;

    public function mount(): void
    {
        abort_unless($this->team() !== null, 404);

        abort_unless(
            Auth::user()->hasTeamPermission($this->team(), TeamPermission::ViewBilling),
            403,
        );
    }

    /**
     * Opens a Pix charge for a wallet recharge. Never credits the wallet —
     * only the webhook does that, once the payment is confirmed.
     */
    public function recharge(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'amount' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_numeric($this->normalizedAmount($value)) || (float) $this->normalizedAmount($value) <= 0) {
                    $fail(__('Informe um valor válido.'));
                }
            }],
        ]);

        $cents = (int) round(((float) $this->normalizedAmount($this->amount)) * 100);
        $user = Auth::user();

        try {
            $intent = resolve(CreateRecharge::class)->handle($this->team(), $cents, PayerData::for($user->name, $user->email));
        } catch (RechargeAmountException) {
            $this->addError('amount', __('Escolha um valor entre :min e :max.', [
                'min' => Number::currency($this->minCents / 100, in: 'BRL', locale: 'pt_BR'),
                'max' => Number::currency($this->maxCents / 100, in: 'BRL', locale: 'pt_BR'),
            ]));

            return;
        }

        $this->intentId = $intent->id;

        Flux::toast(variant: 'success', text: __('Escaneie o Pix para concluir a recarga.'));
    }

    #[Computed]
    public function balance(): int
    {
        return Wallet::forTeam($this->team())->balance_cents;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->hasTeamPermission($this->team(), TeamPermission::ManageBilling);
    }

    /**
     * The open recharge intent, scoped to the current team like every
     * tenant-aware model — the wallet is a team's, not a person's, so
     * showing whichever teammate opened the charge is correct here.
     */
    #[Computed]
    public function intent(): ?PaymentIntent
    {
        if ($this->intentId === null) {
            return null;
        }

        return PaymentIntent::query()->find($this->intentId);
    }

    /**
     * The team's most recent ledger lines, newest first — tenant-scoped like
     * every WalletTransaction query, so this never needs an explicit team
     * filter.
     *
     * @return Collection<int, WalletTransaction>
     */
    #[Computed]
    public function ledger(): Collection
    {
        return WalletTransaction::query()->latest()->limit(20)->get();
    }

    /**
     * The team's recharge payment intents. Mirrors settings/⚡billing.blade.php's
     * payments() computed, filtered to the Recharge purpose instead of Plan.
     *
     * @return Collection<int, PaymentIntent>
     */
    #[Computed]
    public function recharges(): Collection
    {
        return PaymentIntent::acrossTenants()
            ->where('team_id', $this->team()->id)
            ->where('purpose', PaymentPurpose::Recharge->value)
            ->latest()
            ->get();
    }

    #[Computed]
    public function minCents(): int
    {
        return Config::integer('billing.recharge.min_cents');
    }

    #[Computed]
    public function maxCents(): int
    {
        return Config::integer('billing.recharge.max_cents');
    }

    /**
     * Reais typed with a comma decimal separator ("50,00") normalized to a
     * dot so (float) parses the whole value — a plain (float) cast on the
     * raw string stops at the comma and silently drops the cents.
     */
    private function normalizedAmount(string $amount): string
    {
        return str_replace(',', '.', trim($amount));
    }

    private function team(): ?Team
    {
        return resolve(TenantManager::class)->current();
    }
}; ?>

<x-fire.glow class="p-6 lg:p-10">
    <div class="mx-auto flex max-w-3xl flex-col gap-8">
        <div>
            <p class="text-xs font-medium tracking-widest text-white/55 uppercase">{{ __('Carteira') }}</p>
            <h1 class="font-display text-2xl font-semibold text-white">{{ __('Saldo e recarga') }}</h1>
            <p class="mt-1 text-sm text-white/55">{{ __('Acompanhe o saldo do time e recarregue via Pix quando precisar.') }}</p>
        </div>

        <x-fire.stat
            :label="__('Saldo disponível')"
            :value="Number::currency($this->balance / 100, in: 'BRL', locale: 'pt_BR')"
            :hint="__('Descontado a cada consulta executada')"
            tone="fire"
        />

        @if ($this->canManage)
            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Recarregar via Pix') }}</flux:heading>

                <form wire:submit="recharge" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <flux:input.group>
                            <flux:input.group.prefix>{{ __('R$') }}</flux:input.group.prefix>
                            <flux:input
                                wire:model="amount"
                                :label="__('Valor da recarga')"
                                placeholder="50,00"
                                inputmode="decimal"
                                class:input="font-mono"
                                data-test="recarga-valor-input"
                            />
                        </flux:input.group>

                        <p class="mt-1 text-xs text-white/40" data-test="recarga-intervalo">
                            {{ __('Entre :min e :max', [
                                'min' => Number::currency($this->minCents / 100, in: 'BRL', locale: 'pt_BR'),
                                'max' => Number::currency($this->maxCents / 100, in: 'BRL', locale: 'pt_BR'),
                            ]) }}
                        </p>

                        @error('amount')
                            <p class="mt-1 text-sm text-flame-danger" data-test="erro-valor">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- O botão da tela-assinatura: gradiente de fogo próprio, em vez do
                         variant="primary" plano usado no resto do produto. --}}
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="recharge"
                        data-test="gerar-pix"
                        class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-fire-400 via-fire-500 to-fire-600 px-5 font-display text-sm font-semibold text-white shadow-[0_0_20px_-4px_rgb(255_107_0_/_60%)] transition motion-safe:hover:scale-[1.01] motion-safe:active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <flux:icon.qr-code wire:loading.remove wire:target="recharge" class="size-4" />
                        <flux:icon.loading wire:loading wire:target="recharge" class="size-4" />
                        <span wire:loading.remove wire:target="recharge">{{ __('Gerar Pix') }}</span>
                        <span wire:loading wire:target="recharge">{{ __('Gerando…') }}</span>
                    </button>
                </form>
            </div>

            @if ($this->intent)
                <div
                    wire:key="pix-{{ $this->intent->id }}"
                    class="rounded-xl border border-fire-500/25 bg-white/2 p-6 motion-safe:animate-ignite"
                    data-test="pix-charge"
                >
                    <div class="flex items-center gap-1.5 text-xs font-medium tracking-widest text-fire-500 uppercase">
                        <flux:icon.qr-code class="size-3.5 shrink-0" />
                        <span>{{ __('Pague com Pix para concluir a recarga') }}</span>
                    </div>

                    <div class="mt-4 flex flex-col items-start gap-4 sm:flex-row">
                        @if ($this->intent->qr_code !== null)
                            <img
                                src="data:image/png;base64,{{ $this->intent->qr_code }}"
                                alt="{{ __('QR code do Pix') }}"
                                class="h-48 w-48 rounded-lg bg-white p-2"
                                data-test="pix-qr-code"
                            >
                        @endif

                        <div class="flex-1 space-y-3" x-data="{
                            copiado: false,
                            async copiar() {
                                try {
                                    await navigator.clipboard.writeText($refs.copiaCola.innerText.trim());
                                    this.copiado = true;
                                    setTimeout(() => this.copiado = false, 2000);
                                } catch (e) {
                                    console.warn('Não foi possível copiar automaticamente.');
                                }
                            },
                        }">
                            <div
                                x-ref="copiaCola"
                                class="rounded-lg bg-zinc-900/60 p-3 font-mono text-xs break-all text-white/80"
                                data-test="pix-copy-paste"
                            >{{ $this->intent->copy_paste }}</div>

                            <button
                                type="button"
                                x-on:click="copiar()"
                                data-test="pix-copiar"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-fire-500/25 px-3 py-1.5 text-xs font-medium text-white/80 transition hover:border-fire-500/50 hover:text-white"
                            >
                                <flux:icon.document-duplicate x-show="!copiado" class="size-3.5" />
                                <flux:icon.check x-show="copiado" x-cloak class="size-3.5 text-ok" />
                                <span x-show="!copiado">{{ __('Copiar código') }}</span>
                                <span x-show="copiado" x-cloak>{{ __('Copiado!') }}</span>
                            </button>

                            <div class="flex flex-wrap items-center gap-x-6 gap-y-1 border-t border-fire-500/14 pt-3 text-sm">
                                <p><span class="text-white/55">{{ __('Valor:') }}</span> <span class="font-mono text-white">{{ Number::currency($this->intent->amount_cents / 100, in: 'BRL', locale: 'pt_BR') }}</span></p>
                                @if ($this->intent->expires_at)
                                    <p><span class="text-white/55">{{ __('Expira') }}</span> <span class="text-white">{{ $this->intent->expires_at->diffForHumans() }}</span></p>
                                @endif
                            </div>

                            {{-- Sem wire:poll aqui de propósito: o webhook credita a carteira
                                 em segundo plano, e recarregar a página já mostra o saldo
                                 atualizado — suficiente para o MVP sem manter uma requisição
                                 se repetindo enquanto a tela fica aberta. --}}
                            <p class="text-xs text-white/40">
                                {{ __('Depois de pagar, atualize a página para ver o saldo atualizado.') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif
        @else
            <p class="text-sm text-white/55" data-test="sem-permissao-recarga">
                {{ __('Fale com quem administra a cobrança do time para recarregar a carteira.') }}
            </p>
        @endif

        <div class="space-y-3">
            <flux:heading size="sm">{{ __('Razão') }}</flux:heading>

            <div class="rounded-xl border border-fire-500/14 bg-white/2 overflow-hidden">
                @forelse ($this->ledger as $entry)
                    @php
                        $isCredit = $entry->type->isCredit();
                        $typeLabel = match ($entry->type) {
                            WalletTransactionType::Recharge => __('Recarga'),
                            WalletTransactionType::Charge => __('Cobrança'),
                            WalletTransactionType::Refund => __('Estorno'),
                            WalletTransactionType::Bonus => __('Bônus'),
                            WalletTransactionType::Adjustment => __('Ajuste'),
                        };
                    @endphp
                    <div
                        wire:key="razao-{{ $entry->id }}"
                        class="flex items-center justify-between gap-4 p-4 {{ ! $loop->last ? 'border-b border-fire-500/14' : '' }}"
                        data-test="razao-linha"
                    >
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $isCredit ? 'border-ok/30 bg-ok/10 text-ok' : 'border-fire-500/30 bg-fire-500/10 text-fire-400' }}">
                                    {{ $typeLabel }}
                                </span>
                                <span class="text-sm text-white/80">{{ $entry->description }}</span>
                            </div>
                            <p class="text-xs text-white/40">{{ $entry->created_at->format('d/m/Y H:i') }}</p>
                        </div>

                        <div class="text-right">
                            <p class="font-mono text-sm {{ $isCredit ? 'text-ok' : 'text-fire-400' }}" data-test="razao-valor">
                                {{ $isCredit ? '+' : '−' }} {{ Number::currency(abs($entry->amount_cents) / 100, in: 'BRL', locale: 'pt_BR') }}
                            </p>
                            <p class="text-xs text-white/40">
                                {{ __('Saldo:') }} {{ Number::currency($entry->balance_after_cents / 100, in: 'BRL', locale: 'pt_BR') }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="p-4 text-sm text-white/55" data-test="razao-vazia">
                        {{ __('Nenhum lançamento ainda — os movimentos da carteira aparecem aqui.') }}
                    </p>
                @endforelse
            </div>
        </div>

        <div class="space-y-3">
            <flux:heading size="sm">{{ __('Recargas') }}</flux:heading>

            <div class="rounded-xl border border-fire-500/14 bg-white/2 overflow-hidden">
                @forelse ($this->recharges as $recharge)
                    <div
                        wire:key="recarga-{{ $recharge->id }}"
                        class="flex items-center justify-between gap-4 p-4 {{ ! $loop->last ? 'border-b border-fire-500/14' : '' }}"
                        data-test="recarga-linha"
                    >
                        <div class="space-y-1">
                            <p class="text-sm text-white/80">{{ $recharge->created_at->format('d/m/Y') }}</p>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $recharge->status === PaymentStatus::Paid ? 'border-ok/30 bg-ok/10 text-ok' : 'border-white/14 bg-white/5 text-white/55' }}">
                                {{ $recharge->status === PaymentStatus::Paid ? __('Paga') : __('Pendente') }}
                            </span>
                        </div>

                        <p class="font-mono text-sm text-white" data-test="recarga-valor">
                            {{ Number::currency($recharge->amount_cents / 100, in: 'BRL', locale: 'pt_BR') }}
                        </p>
                    </div>
                @empty
                    <p class="p-4 text-sm text-white/55" data-test="recargas-vazia">
                        {{ __('Nenhuma recarga ainda — gere um Pix acima para começar.') }}
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</x-fire.glow>
