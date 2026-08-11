<?php

use App\Enums\ApiAbility;
use App\Exceptions\PlanFeatureException;
use App\Models\ApiToken;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API tokens')] class extends Component {
    public string $name = '';

    public string $ability = 'read';

    /**
     * O token em texto puro, mostrado uma única vez.
     *
     * Fica numa propriedade travada porque não é entrada de ninguém: exibir
     * de novo depois exigiria guardá-lo, e guardar em texto puro o que serve
     * de credencial é o oposto do que o hash existe para fazer.
     */
    #[Locked]
    public ?string $plainTextToken = null;

    /**
     * @return array<int, ApiToken>
     */
    #[Computed]
    public function tokens(): array
    {
        $team = $this->team();

        if ($team === null) {
            return [];
        }

        return ApiToken::query()
            ->where('tokenable_type', Auth::user()->getMorphClass())
            ->where('tokenable_id', Auth::id())
            ->where('team_id', $team->id)
            ->latest()
            ->get()
            ->all();
    }

    /**
     * @return array<int, ApiAbility>
     */
    #[Computed]
    public function abilities(): array
    {
        return ApiAbility::cases();
    }

    /**
     * Whether this team may use the API.
     *
     * Plan or override, asked the same way the middleware that enforces this
     * on the API itself asks — support can grant the capability to one team
     * without touching the plan config, and this screen has to see that grant
     * too, not just the plan the team pays for. Neither question is cached:
     * the plan changes with the subscription, and a screen that disagreed with
     * the API about who may create a token would be worse than no screen.
     */
    #[Computed]
    public function hasApiAccess(): bool
    {
        $team = $this->team();

        return $team !== null && $this->allows($team);
    }

    /**
     * The plan a team without API access is told to leave, by name.
     */
    #[Computed]
    public function plan(): ?Plan
    {
        $team = $this->team();

        return $team === null ? null : TeamBilling::for($team)->plan();
    }

    public function createToken(): void
    {
        $team = $this->team();

        abort_if($team === null, 404);

        try {
            $this->ensureApiAccess($team);
        } catch (PlanFeatureException $e) {
            // O @if da view só decide o que é renderizado; quem chama esta
            // ação direto pelo protocolo do Livewire, pulando o markup, não
            // pode sair daqui com um token que a própria API vai recusar do
            // mesmo jeito. Mesmo padrão de ⚡invite-member-modal: erro de
            // formulário em vez de deixar a exceção subir.
            $this->addError('name', $e->getMessage());

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'ability' => ['required', Rule::enum(ApiAbility::class)],
        ]);

        $ability = ApiAbility::from($validated['ability']);

        $token = Auth::user()->createToken($validated['name'], $ability->implies());

        // O time é escrito depois porque o createToken do Sanctum não conhece
        // esta coluna. Sem isto o token nasceria sem time e seria recusado na
        // primeira chamada.
        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $team->id]);

        $this->plainTextToken = $token->plainTextToken;

        $this->reset('name', 'ability');
        unset($this->tokens);
    }

    public function revoke(int $tokenId): void
    {
        $team = $this->team();

        abort_if($team === null, 404);

        // Escopado pelo dono e pelo time: um id de token de outra pessoa não
        // deve ser revogável a partir daqui.
        ApiToken::query()
            ->whereKey($tokenId)
            ->where('tokenable_id', Auth::id())
            ->where('team_id', $team->id)
            ->delete();

        unset($this->tokens);

        Flux::toast(variant: 'success', text: __('Token revoked.'));
    }

    public function dismissToken(): void
    {
        $this->plainTextToken = null;
    }

    /**
     * Refuses to create a token for a team whose plan does not include the
     * API — the same rule the API middleware enforces, asked again here
     * because hiding the form is not access control: the action is still
     * reachable straight through the Livewire protocol itself.
     */
    private function ensureApiAccess(App\Models\Team $team): void
    {
        if (! $this->allows($team)) {
            throw PlanFeatureException::required('api');
        }
    }

    /**
     * The plan the team is entitled to right now, or the grant support left on
     * top of it.
     */
    private function allows(App\Models\Team $team): bool
    {
        return TeamBilling::for($team)->plan()->hasFeature('api')
            || Feature::for($team)->active('api');
    }

    private function team(): ?App\Models\Team
    {
        return app(TenantManager::class)->current();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('API tokens') }}</flux:heading>

    <x-pages::settings.layout :heading="__('API tokens')" :subheading="__('Tokens act on this team, with the abilities you choose')">
        <div class="my-6 space-y-8">
            @unless ($this->hasApiAccess)
                {{-- Sumir sem explicação é o que gera chamado de suporte: quem
                     está num plano sem API precisa ver o plano atual, o
                     recurso que falta e o caminho para os planos, não uma
                     tela que perdeu metade do conteúdo sem motivo aparente.
                     Só o formulário de criação fica atrás disto — a lista
                     abaixo continua visível para quem já tinha token, porque
                     perder o recurso não pode significar perder também o
                     único lugar onde revogar o que ficou para trás. --}}
                <flux:callout variant="warning" icon="lock-closed">
                    <flux:callout.heading>{{ __('The API is not part of this plan') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Your :plan plan does not include :feature.', ['plan' => $this->plan?->name, 'feature' => 'api']) }}
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button :href="route('billing.edit')" variant="primary" wire:navigate>{{ __('See plans') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endunless

            @if ($this->hasApiAccess)
                @if ($plainTextToken)
                    {{-- Mostrado uma vez só: guardá-lo para exibir de novo seria
                         guardar em texto puro o que o hash existe para proteger. --}}
                    <div class="rounded-lg border border-green-300 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950/30 space-y-3" data-test="new-token">
                        <flux:heading size="sm">{{ __('Copy your token now') }}</flux:heading>
                        <flux:text class="text-sm">{{ __('This is the only time it will be shown.') }}</flux:text>

                        <flux:input readonly :value="$plainTextToken" copyable />

                        <flux:button size="sm" variant="outline" wire:click="dismissToken">
                            {{ __('Done') }}
                        </flux:button>
                    </div>
                @endif

                <form wire:submit="createToken" class="space-y-4">
                    <flux:input wire:model="name" :label="__('Token name')" required data-test="token-name" />

                    <flux:select wire:model="ability" :label="__('Abilities')" data-test="token-ability">
                        @foreach ($this->abilities as $ability)
                            <flux:select.option value="{{ $ability->value }}">{{ $ability->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:button variant="primary" type="submit" data-test="create-token">
                        {{ __('Create token') }}
                    </flux:button>
                </form>
            @endif

            {{-- Sempre visível, com ou sem o recurso: um time que perdeu o
                 acesso à API fica com tokens que já existiam, e ver que eles
                 continuam aí — inertes, porque a rota da API recusa pelo
                 mesmo Pennant — é informação, não ruído. Esconder esta lista
                 junto com o formulário deixaria esse time sem como revogá-los. --}}
            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Active tokens') }}</flux:heading>

                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    @forelse ($this->tokens as $token)
                        <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-medium">{{ $token->name }}</p>
                                    @foreach ($token->abilities as $ability)
                                        <flux:badge size="sm">{{ $ability }}</flux:badge>
                                    @endforeach
                                </div>
                                <flux:text class="text-xs">
                                    {{ __('Created :when', ['when' => $token->created_at?->diffForHumans()]) }}
                                    <span class="opacity-50 mx-1">/</span>
                                    {{ $token->last_used_at
                                        ? __('Last used :when', ['when' => $token->last_used_at->diffForHumans()])
                                        : __('Never used') }}
                                </flux:text>
                            </div>

                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                icon:variant="outline"
                                wire:click="revoke({{ $token->id }})"
                                wire:confirm="{{ __('Revoke this token? Anything using it stops working immediately.') }}"
                                :data-test="'revoke-token-'.$token->id"
                                class="text-red-500 hover:text-red-600"
                            />
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="font-medium">{{ __('No tokens yet') }}</p>
                            <flux:text class="mt-1">{{ __('Create one to use the API on behalf of this team.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
