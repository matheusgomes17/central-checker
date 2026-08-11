<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    /**
     * Estado da grade, aninhado por tópico e canal.
     *
     * Aninhado e não com chave "topico.canal": o Livewire trata ponto como
     * caminho, então uma chave plana com ponto nunca casaria com o
     * wire:model do formulário.
     *
     * @var array<string, array<string, bool>>
     */
    public array $preferences = [];

    public function mount(): void
    {
        foreach (NotificationTopic::cases() as $topic) {
            foreach (NotificationChannel::optional() as $channel) {
                $this->preferences[$topic->value][$channel->value] =
                    Auth::user()->wantsNotification($topic, $channel);
            }
        }
    }

    /**
     * @return array<int, NotificationTopic>
     */
    #[Computed]
    public function topics(): array
    {
        return NotificationTopic::cases();
    }

    /**
     * @return array<int, NotificationChannel>
     */
    #[Computed]
    public function channels(): array
    {
        return NotificationChannel::optional();
    }

    public function save(): void
    {
        $user = Auth::user();

        foreach (NotificationTopic::cases() as $topic) {
            foreach (NotificationChannel::optional() as $channel) {
                $user->setNotificationPreference(
                    $topic,
                    $channel,
                    (bool) ($this->preferences[$topic->value][$channel->value] ?? true),
                );
            }
        }

        Flux::toast(variant: 'success', text: __('Notification settings saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Choose what you hear about, and how')">
        <form wire:submit="save" class="my-6 space-y-6">
            @foreach ($this->topics as $topic)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 space-y-3">
                    <div>
                        <flux:heading size="sm">{{ $topic->label() }}</flux:heading>
                        <flux:text class="text-sm">{{ $topic->description() }}</flux:text>
                    </div>

                    @if ($topic->isOptional())
                        <div class="flex flex-wrap gap-6">
                            @foreach ($this->channels as $channel)
                                <flux:switch
                                    wire:model="preferences.{{ $topic->value }}.{{ $channel->value }}"
                                    :label="$channel->label()"
                                    :data-test="'pref-'.$topic->value.'-'.$channel->value"
                                />
                            @endforeach
                        </div>
                    @else
                        {{-- Desligar o aviso de que a senha mudou desliga a única
                             coisa que diria que não foi você quem mudou. --}}
                        <flux:text class="text-sm" variant="subtle">
                            {{ __('Security notifications are always sent.') }}
                        </flux:text>
                    @endif
                </div>
            @endforeach

            <flux:text class="text-sm" variant="subtle">
                {{ __('The in-app inbox always keeps a record, whatever you choose here.') }}
            </flux:text>

            <flux:button variant="primary" type="submit" data-test="save-notification-preferences">
                {{ __('Save') }}
            </flux:button>
        </form>
    </x-pages::settings.layout>
</section>
