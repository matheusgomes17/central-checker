<?php

use App\Enums\WebhookEvent;
use App\Models\WebhookEndpoint;
use App\Support\TenantManager;
use App\Support\Webhooks\WebhookSignature;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Webhooks')] class extends Component {
    public string $url = '';

    public string $description = '';

    /**
     * @var array<int, string>
     */
    public array $events = [];

    /**
     * Qual segredo está revelado no momento.
     *
     * Travada porque não é entrada de ninguém: quem escolhe é o clique em
     * revelar, e aceitar um id vindo do navegador aqui seria aceitar que a
     * pessoa peça o segredo de qualquer endpoint.
     */
    #[Locked]
    public ?int $revealedId = null;

    /**
     * @return array<int, WebhookEndpoint>
     */
    #[Computed]
    public function endpoints(): array
    {
        if ($this->team() === null) {
            return [];
        }

        return WebhookEndpoint::query()->latest()->get()->all();
    }

    /**
     * @return array<int, WebhookEvent>
     */
    #[Computed]
    public function availableEvents(): array
    {
        return WebhookEvent::cases();
    }

    public function create(): void
    {
        $team = $this->team();

        abort_if($team === null, 404);

        Gate::authorize('manageWebhooks', $team);

        $validated = $this->validate([
            // https, e não url: o corpo leva dados do time e a assinatura não
            // esconde nada — ela prova a origem, não protege o conteúdo.
            'url' => ['required', 'url:https', 'max:255'],
            'description' => ['nullable', 'string', 'max:120'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(WebhookEvent::values())],
        ]);

        $endpoint = new WebhookEndpoint($validated);
        $endpoint->secret = WebhookEndpoint::newSecret();
        $endpoint->save();

        $this->revealedId = $endpoint->id;

        $this->reset('url', 'description', 'events');
        unset($this->endpoints);

        Flux::toast(variant: 'success', text: __('Endpoint created.'));
    }

    public function reveal(int $endpointId): void
    {
        $team = $this->team();

        abort_if($team === null, 404);

        Gate::authorize('manageWebhooks', $team);

        // Pelo escopo do model, não pelo id cru: o escopo é o que impede que
        // o id de um endpoint de outro time devolva o segredo dele.
        $this->revealedId = WebhookEndpoint::query()->whereKey($endpointId)->value('id');
    }

    public function delete(int $endpointId): void
    {
        $team = $this->team();

        abort_if($team === null, 404);

        Gate::authorize('manageWebhooks', $team);

        WebhookEndpoint::query()->whereKey($endpointId)->delete();

        $this->revealedId = null;
        unset($this->endpoints);

        Flux::toast(variant: 'success', text: __('Endpoint removed.'));
    }

    /**
     * Reativa um endpoint que foi desligado por falhar demais.
     */
    public function reactivate(int $endpointId): void
    {
        $team = $this->team();

        abort_if($team === null, 404);

        Gate::authorize('manageWebhooks', $team);

        WebhookEndpoint::query()
            ->whereKey($endpointId)
            ->update(['is_active' => true, 'consecutive_failures' => 0]);

        unset($this->endpoints);
    }

    public function signatureHeader(): string
    {
        return WebhookSignature::HEADER;
    }

    private function team(): ?App\Models\Team
    {
        return app(TenantManager::class)->current();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Webhooks') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Webhooks')" :subheading="__('Receive signed events when things happen in this team')">
        <div class="my-6 space-y-8">
            <form wire:submit="create" class="space-y-4">
                <flux:input wire:model="url" :label="__('Endpoint URL')" placeholder="https://example.com/webhooks" required data-test="webhook-url" />
                <flux:input wire:model="description" :label="__('Description')" :placeholder="__('Optional')" data-test="webhook-description" />

                <flux:checkbox.group wire:model="events" :label="__('Events')">
                    @foreach ($this->availableEvents as $event)
                        <flux:checkbox :value="$event->value" :label="$event->label()" />
                    @endforeach
                </flux:checkbox.group>

                <flux:error name="events" />

                <flux:button variant="primary" type="submit" data-test="create-webhook">
                    {{ __('Add endpoint') }}
                </flux:button>
            </form>

            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Endpoints') }}</flux:heading>

                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    @forelse ($this->endpoints as $endpoint)
                        <div class="p-4 space-y-3 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                            <div class="flex items-start justify-between gap-4">
                                <div class="space-y-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium truncate">{{ $endpoint->url }}</p>
                                        @if (! $endpoint->is_active)
                                            <flux:badge size="sm" color="red">{{ __('Disabled') }}</flux:badge>
                                        @endif
                                    </div>

                                    <flux:text class="text-xs">
                                        @if ($endpoint->last_delivered_at)
                                            {{ __('Last delivery :when returned :status', [
                                                'when' => $endpoint->last_delivered_at->diffForHumans(),
                                                'status' => $endpoint->last_status,
                                            ]) }}
                                        @else
                                            {{ __('No delivery yet') }}
                                        @endif
                                    </flux:text>

                                    <div class="flex flex-wrap gap-1 pt-1">
                                        @foreach ($endpoint->events as $event)
                                            <flux:badge size="sm">{{ $event }}</flux:badge>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="flex items-center gap-1 shrink-0">
                                    @if (! $endpoint->is_active)
                                        <flux:button size="sm" variant="outline" wire:click="reactivate({{ $endpoint->id }})" :data-test="'reactivate-webhook-'.$endpoint->id">
                                            {{ __('Re-enable') }}
                                        </flux:button>
                                    @endif

                                    <flux:button size="sm" variant="ghost" wire:click="reveal({{ $endpoint->id }})" :data-test="'reveal-webhook-'.$endpoint->id">
                                        {{ __('Secret') }}
                                    </flux:button>

                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        icon:variant="outline"
                                        wire:click="delete({{ $endpoint->id }})"
                                        wire:confirm="{{ __('Remove this endpoint? It stops receiving events immediately.') }}"
                                        :data-test="'delete-webhook-'.$endpoint->id"
                                        class="text-red-500 hover:text-red-600"
                                    />
                                </div>
                            </div>

                            @if ($revealedId === $endpoint->id)
                                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 space-y-2 dark:border-zinc-700 dark:bg-zinc-900" data-test="webhook-secret">
                                    <flux:input readonly :value="$endpoint->secret" copyable />
                                    <flux:text class="text-xs">
                                        {{ __('Every delivery carries a :header header of the form t=timestamp,v1=hmac. Recompute the HMAC over "timestamp.body" with this secret and compare; refuse anything older than :tolerance seconds.', [
                                            'header' => $this->signatureHeader(),
                                            'tolerance' => \App\Support\Webhooks\WebhookSignature::TOLERANCE,
                                        ]) }}
                                    </flux:text>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="font-medium">{{ __('No endpoints yet') }}</p>
                            <flux:text class="mt-1">{{ __('Add one to be told when members join or leave.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
