<?php

declare(strict_types=1);

use App\Actions\Billing\PurchasePlan;
use App\Enums\TeamPermission;
use App\Models\PlanCheck;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\Subscriptions;
use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Payments\Models\PaymentIntent;
use Modules\Payments\Support\PayerData;

new #[Title('Planos')] class extends Component {
    /**
     * Id of the Pix intent opened by {@see self::subscribe()}, set only
     * server-side. Locked so a crafted Livewire request can't swap it for
     * another team's intent id — the intent itself is team-scoped, but the
     * id alone shouldn't be attacker-controlled.
     */
    #[Locked]
    public ?int $intentId = null;

    /**
     * Key of the plan waiting for downgrade confirmation, set only
     * server-side inside {@see self::subscribe()} — always re-validated
     * against Plan::purchasable() before use, never trusted as-is.
     */
    public ?string $confirming = null;

    public function mount(): void
    {
        abort_unless($this->team() !== null, 404);

        abort_unless(
            Auth::user()->hasTeamPermission($this->team(), TeamPermission::ViewBilling),
            403,
        );
    }

    /**
     * `confirming` isn't #[Locked] — the "Cancelar" button needs a client
     * `$set('confirming', null)` — so a tampered request can set it to a key
     * outside Plan::purchasable(). Drop it back to null right away instead
     * of letting a stale/invalid key linger for the view to index.
     */
    public function updatedConfirming(): void
    {
        if ($this->confirming !== null && ! array_key_exists($this->confirming, $this->plans())) {
            $this->confirming = null;
        }
    }

    /**
     * Opens a Pix charge for the plan, asking first when the plan is smaller
     * than what the team is already using.
     *
     * The subscription only activates once the webhook confirms the payment
     * landed — this only ever opens the charge.
     */
    public function subscribe(string $key): void
    {
        abort_unless($this->canManage, 403);

        $plan = $this->plans()[$key] ?? abort(404);

        if ($this->wouldLeaveTeamOverTheCeiling($plan) && $this->confirming !== $key) {
            $this->confirming = $key;

            return;
        }

        $this->confirming = null;

        $user = Auth::user();
        $intent = resolve(PurchasePlan::class)->handle($this->team(), $plan, PayerData::for($user->name, $user->email));

        $this->intentId = $intent->id;

        Flux::toast(variant: 'success', text: __('Escaneie o Pix para ativar :plano.', ['plano' => $plan->name]));
    }

    #[Computed]
    public function billing(): TeamBilling
    {
        return TeamBilling::for($this->team());
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->hasTeamPermission($this->team(), TeamPermission::ManageBilling);
    }

    /**
     * @return array<string, Plan>
     */
    #[Computed]
    public function plans(): array
    {
        return Plan::purchasable();
    }

    /**
     * Consultas incluídas por plano — o catálogo é global, então a consulta
     * não passa pelo escopo de tenant.
     *
     * @return array<string, Collection<int, PlanCheck>>
     */
    #[Computed]
    public function includedChecks(): array
    {
        $includedChecks = [];

        foreach (array_keys($this->plans()) as $key) {
            $includedChecks[$key] = PlanCheck::query()->where('plan', $key)->with('check')->get();
        }

        return $includedChecks;
    }

    /**
     * O Pix em aberto, escopado ao time como todo model tenant-aware — a
     * cobrança é do time, não de quem abriu.
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
     * Days left in the active paid period, ceiled so any partial day still
     * reads as "at least one day left" instead of rounding down to zero.
     * Null when there is no active subscription — mirrors ⚡dashboard.blade.php.
     */
    #[Computed]
    public function daysRemaining(): ?int
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return null;
        }

        return (int) ceil(now()->diffInSeconds($subscription->current_period_end) / 86400);
    }

    private function subscription(): ?PlanSubscription
    {
        return resolve(Subscriptions::class)->active($this->team());
    }

    private function team(): ?Team
    {
        return resolve(TenantManager::class)->current();
    }

    /**
     * Whether the team already uses more seats than a plan would allow.
     *
     * Reads TeamBilling::usage() directly, not the per-check includedChecks()
     * list above — that one is shaped for the included-consultas display,
     * not for reading the members ceiling.
     */
    private function wouldLeaveTeamOverTheCeiling(Plan $plan): bool
    {
        $limit = $plan->limit('members');

        return $limit !== null && $this->billing->usage('members')['used'] > $limit;
    }
}; ?>

<x-fire.glow class="p-6 lg:p-10">
    <div class="mx-auto flex max-w-5xl flex-col gap-8">
        <div>
            <p class="text-xs font-medium tracking-widest text-white/55 uppercase">{{ __('Planos') }}</p>
            <h1 class="font-display text-2xl font-semibold text-white">{{ __('Plano do time e assinatura via Pix') }}</h1>
            <p class="mt-1 text-sm text-white/55">{{ __('Compare os planos e veja quantas consultas cada um inclui por mês.') }}</p>
        </div>

        <div class="rounded-xl border border-fire-500/14 bg-white/2 p-6" data-test="plano-atual">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading>{{ $this->billing->plan()->name }}</flux:heading>
                <flux:badge size="sm" :color="$this->billing->status()->color()">
                    {{ $this->billing->status()->label() }}
                </flux:badge>
            </div>

            <x-fire.plan-timer
                class="mt-4"
                :label="__('Período pago')"
                :daysLeft="$this->daysRemaining"
            />

            @if ($this->billing->onTrial())
                <flux:text class="mt-3 text-sm text-white/70">
                    {{ __('Teste termina :quando', ['quando' => $this->billing->trialEndsAt()?->diffForHumans()]) }}
                </flux:text>
            @endif

            @if ($this->billing->status()->needsAttention())
                <flux:callout class="mt-4" variant="warning" data-test="plano-atencao">
                    {{ __('Esta assinatura está expirando. Renove via Pix para manter o plano.') }}
                </flux:callout>
            @endif
        </div>

        <div class="space-y-3">
            <flux:heading size="sm">{{ __('Planos disponíveis') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($this->plans as $key => $plan)
                    <div
                        wire:key="plano-{{ $key }}"
                        class="flex flex-col gap-4 rounded-xl border border-fire-500/14 bg-white/2 p-6"
                        data-test="plano-card-{{ $key }}"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <flux:heading size="sm">{{ $plan->name }}</flux:heading>

                                @if ($this->billing->plan()->key === $key)
                                    <flux:badge size="sm" color="green">{{ __('Plano atual') }}</flux:badge>
                                @endif
                            </div>
                            <p class="font-mono text-2xl font-semibold text-white">
                                {{ Number::currency($plan->price / 100, in: 'BRL', locale: 'pt_BR') }}
                                <span class="text-sm font-normal text-white/55">{{ __('/mês') }}</span>
                            </p>
                        </div>

                        <div class="space-y-1.5 border-t border-fire-500/14 pt-4">
                            <p class="text-xs font-medium tracking-widest text-white/55 uppercase">{{ __('Consultas incluídas') }}</p>

                            @forelse ($this->includedChecks[$key] as $includedCheck)
                                <div class="flex items-center justify-between gap-4 text-sm" data-test="plano-{{ $key }}-consulta">
                                    <span class="text-white/80">{{ $includedCheck->check->name }}</span>
                                    <span class="font-mono text-white/55">
                                        {{ $includedCheck->isUnlimited() ? __('ilimitado') : __(':quota/mês', ['quota' => $includedCheck->quota]) }}
                                    </span>
                                </div>
                            @empty
                                <p class="text-sm text-white/40">{{ __('Nenhuma consulta incluída neste plano.') }}</p>
                            @endforelse
                        </div>

                        @if ($this->canManage && $this->billing->plan()->key !== $key)
                            <x-fire.cta
                                type="button"
                                wire:click="subscribe('{{ $key }}')"
                                wire:loading.attr="disabled"
                                wire:target="subscribe('{{ $key }}')"
                                data-test="plano-assinar-{{ $key }}"
                                class="mt-auto w-full"
                            >
                                <flux:icon.fire wire:loading.remove wire:target="subscribe('{{ $key }}')" class="size-4" />
                                <flux:icon.loading wire:loading wire:target="subscribe('{{ $key }}')" class="size-4" />
                                <span wire:loading.remove wire:target="subscribe('{{ $key }}')">
                                    {{ $this->billing->subscribed() ? __('Renovar :plano', ['plano' => $plan->name]) : __('Assinar :plano', ['plano' => $plan->name]) }}
                                </span>
                                <span wire:loading wire:target="subscribe('{{ $key }}')">{{ __('Gerando…') }}</span>
                            </x-fire.cta>
                        @endif
                    </div>
                @endforeach
            </div>

            @php($confirmingPlan = $this->confirming !== null ? ($this->plans[$this->confirming] ?? null) : null)
            @if ($confirmingPlan)
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="downgrade-confirm">
                    <flux:callout.heading>{{ __('Este plano é menor que o uso atual') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __(':usados de :limite membros em uso. Ninguém é removido, e ninguém novo entra até que vagas sejam liberadas.', [
                            'usados' => $this->billing->usage('members')['used'],
                            'limite' => $confirmingPlan->limit('members'),
                        ]) }}
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button wire:click="subscribe('{{ $confirmingPlan->key }}')" variant="danger" size="sm" data-test="downgrade-confirm-button">
                            {{ __('Trocar mesmo assim') }}
                        </flux:button>
                        <flux:button wire:click="$set('confirming', null)" variant="ghost" size="sm">{{ __('Cancelar') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif
        </div>

        @if ($this->intent)
            <div
                wire:key="pix-{{ $this->intent->id }}"
                class="rounded-xl border border-fire-500/25 bg-white/2 p-6 motion-safe:animate-ignite"
                data-test="pix-charge"
            >
                <div class="flex items-center gap-1.5 text-xs font-medium tracking-widest text-fire-500 uppercase">
                    <flux:icon.qr-code class="size-3.5 shrink-0" />
                    <span>{{ __('Pague com Pix para ativar o plano') }}</span>
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

                        <p class="text-xs text-white/40">
                            {{ __('Depois de pagar, atualize a página para ver o plano ativado.') }}
                        </p>
                    </div>
                </div>
            </div>
        @endif

        @unless ($this->canManage)
            <p class="text-sm text-white/55" data-test="sem-permissao-assinar">
                {{ __('Fale com quem administra a cobrança do time para assinar ou trocar de plano.') }}
            </p>
        @endunless
    </div>
</x-fire.glow>
