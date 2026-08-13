<?php

use App\Actions\Billing\PurchasePlan;
use App\Enums\TeamPermission;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Payments\Enums\PaymentPurpose;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\PaymentIntent;
use Modules\Payments\Support\PayerData;

new #[Title('Billing')] class extends Component {
    public ?string $confirming = null;

    public ?string $pixCopyPaste = null;

    public ?string $pixQrCode = null;

    public ?string $pixPlanName = null;

    public function mount(): void
    {
        abort_unless($this->team() !== null, 404);

        abort_unless(
            Auth::user()->hasTeamPermission($this->team(), TeamPermission::ViewBilling),
            403,
        );
    }

    /**
     * Open a Pix charge for the plan, asking first when the plan is smaller
     * than what the team is already using.
     *
     * The subscription only activates once the webhook confirms the payment
     * landed — this only ever opens the charge.
     */
    public function subscribe(string $key): void
    {
        abort_unless($this->canManage, 403);

        $team = $this->team();

        $plan = $this->plans()[$key] ?? Plan::find($key);

        if ($this->wouldLeaveTeamOverTheCeiling($plan) && $this->confirming !== $key) {
            $this->confirming = $key;

            return;
        }

        $this->confirming = null;

        $user = Auth::user();
        $intent = resolve(PurchasePlan::class)->handle($team, $plan, PayerData::for($user->name, $user->email));

        $this->pixCopyPaste = $intent->copy_paste;
        $this->pixQrCode = $intent->qr_code;
        $this->pixPlanName = $plan->name;

        Flux::toast(variant: 'success', text: __('Scan the Pix code to activate :plan.', ['plan' => $plan->name]));
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
     * @return array<int, array{resource: string, limit: int|null, used: int, remaining: int|null}>
     */
    #[Computed]
    public function usage(): array
    {
        $rows = [];

        foreach (array_keys($this->billing->plan()->limits) as $resource) {
            $rows[] = ['resource' => $resource, ...$this->billing->usage($resource)];
        }

        return $rows;
    }

    /**
     * @return \Illuminate\Support\Collection<int, PaymentIntent>
     */
    #[Computed]
    public function payments(): Collection
    {
        return PaymentIntent::acrossTenants()
            ->where('team_id', $this->team()->id)
            ->where('purpose', PaymentPurpose::Plan->value)
            ->latest()
            ->get();
    }

    private function team(): ?App\Models\Team
    {
        return app(TenantManager::class)->current();
    }

    /**
     * Whether the team already uses more seats than a plan would allow.
     *
     * Reads TeamBilling::usage() directly rather than the computed usage()
     * list above: that list is shaped for iteration over every metered
     * resource, keyed by position, not by resource name.
     */
    private function wouldLeaveTeamOverTheCeiling(Plan $plan): bool
    {
        $limit = $plan->limit('members');

        return $limit !== null && $this->billing->usage('members')['used'] > $limit;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Billing') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Billing')" :subheading="__('Plan, usage and payments for this team')">
        <div class="my-6 space-y-8">
            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <flux:heading>{{ $this->billing->plan()->name }}</flux:heading>
                        <flux:badge size="sm" :color="$this->billing->status()->color()">
                            {{ $this->billing->status()->label() }}
                        </flux:badge>
                    </div>

                    @if ($this->billing->onTrial())
                        <flux:text class="text-sm">
                            {{ __('Trial ends :when', ['when' => $this->billing->trialEndsAt()?->diffForHumans()]) }}
                        </flux:text>
                    @endif
                </div>

                @if ($this->billing->status()->needsAttention())
                    {{-- O aviso fica na própria página, não só no e-mail: quem
                         chega aqui já está procurando o que fazer. --}}
                    <flux:callout class="mt-4" variant="warning" data-test="billing-attention">
                        {{ __('This subscription is expiring. Renew via Pix to keep your plan.') }}
                    </flux:callout>
                @endif
            </div>

            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Usage') }}</flux:heading>

                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    @foreach ($this->usage as $row)
                        <div class="p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                            <div class="flex items-center justify-between gap-4">
                                <span class="font-medium">{{ __(ucfirst(str_replace('_', ' ', $row['resource']))) }}</span>
                                <div class="flex items-center gap-2">
                                    @if ($row['limit'] !== null && $row['used'] > $row['limit'])
                                        {{-- Time acima do teto continua com todo mundo: nada
                                             aqui remove ninguém, só torna o excesso visível. --}}
                                        <flux:badge color="amber" size="sm">{{ __('Above your plan') }}</flux:badge>
                                    @endif
                                    <flux:text class="text-sm">
                                        {{ $row['used'] }} /
                                        {{ $row['limit'] === null ? __('Unlimited') : $row['limit'] }}
                                    </flux:text>
                                </div>
                            </div>

                            @if ($row['limit'] !== null && $row['used'] > $row['limit'])
                                <flux:text size="sm" class="mt-1 text-zinc-600 dark:text-zinc-400">
                                    {{ __('This team is over its plan ceiling. Upgrade the plan or free up seats — nobody was removed.') }}
                                </flux:text>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Payments') }}</flux:heading>

                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    @forelse ($this->payments as $payment)
                        <div class="flex items-center justify-between gap-4 p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                            <div class="space-y-1">
                                <flux:text class="font-medium">{{ $payment->created_at->format('d/m/Y') }}</flux:text>
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" :color="$payment->status === PaymentStatus::Paid ? 'green' : 'zinc'">
                                        {{ $payment->status === PaymentStatus::Paid ? __('Paid') : __('Pending') }}
                                    </flux:badge>
                                    <flux:text class="text-sm">{{ number_format($payment->amount_cents / 100, 2) }}</flux:text>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="p-4">
                            <flux:text class="text-zinc-600 dark:text-zinc-400">{{ __('No payments yet') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            @if ($this->canManage)
                <div class="space-y-3">
                    <flux:heading size="sm">{{ __('Plans') }}</flux:heading>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($this->plans as $key => $plan)
                            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 space-y-3">
                                <div>
                                    <flux:heading size="sm">{{ $plan->name }}</flux:heading>
                                    <flux:text class="text-sm">
                                        {{ __(':amount / month', ['amount' => number_format($plan->price / 100, 2)]) }}
                                    </flux:text>
                                </div>

                                @if ($this->billing->plan()->key === $key)
                                    <flux:badge size="sm" color="green">{{ __('Current plan') }}</flux:badge>
                                @else
                                    <flux:button wire:click="subscribe('{{ $key }}')" variant="primary" size="sm" class="w-full" :data-test="'billing-choose-'.$key">
                                        {{ $this->billing->subscribed() ? __('Renew :plan', ['plan' => $plan->name]) : __('Subscribe to :plan', ['plan' => $plan->name]) }}
                                    </flux:button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if ($this->confirming)
                        <flux:callout variant="warning" icon="exclamation-triangle" data-test="downgrade-confirm">
                            <flux:callout.heading>{{ __('This plan is smaller than what the team uses') }}</flux:callout.heading>
                            <flux:callout.text>
                                {{ __(':used of :limit seats in use. Nobody is removed, and no one new can be added until seats are freed.', [
                                    'used' => $this->billing->usage('members')['used'],
                                    'limit' => Plan::find($this->confirming)->limit('members'),
                                ]) }}
                            </flux:callout.text>
                            <x-slot name="actions">
                                <flux:button wire:click="subscribe('{{ $this->confirming }}')" variant="danger" size="sm" data-test="downgrade-confirm-button">{{ __('Change anyway') }}</flux:button>
                                <flux:button wire:click="$set('confirming', null)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                            </x-slot>
                        </flux:callout>
                    @endif

                    @if ($pixCopyPaste !== null)
                        <flux:callout icon="qr-code" data-test="pix-charge">
                            <flux:callout.heading>{{ __('Pay with Pix to activate :plan', ['plan' => $pixPlanName]) }}</flux:callout.heading>
                            <flux:callout.text>
                                <div class="space-y-3">
                                    @if ($pixQrCode !== null)
                                        <img src="data:image/png;base64,{{ $pixQrCode }}" alt="{{ __('Pix QR code') }}" class="h-48 w-48" data-test="pix-qr-code">
                                    @endif

                                    <div class="rounded-lg bg-zinc-100 p-3 font-mono text-xs break-all dark:bg-zinc-800" data-test="pix-copy-paste">
                                        {{ $pixCopyPaste }}
                                    </div>
                                </div>
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
