<?php

use App\Concerns\PasswordValidationRules;
use App\Support\UserAgent;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Lists the user's active sessions and lets them revoke the others.
 *
 * Reading and deleting rows straight from the session table is what makes
 * revocation immediate. That only works on the database driver, so the whole
 * section hides itself on any other one rather than rendering a list that is
 * always empty and a button that does nothing.
 */
new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    public bool $showConfirmation = false;

    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return array{sessions: array<int, array{id: string, device: string, ip: string, last_active: string, last_active_at: string, is_current: bool}>}
     */
    public function with(): array
    {
        return ['sessions' => $this->supported() ? $this->sessions() : []];
    }

    /**
     * Revoke every session except the one making this request.
     */
    public function logoutOtherSessions(): void
    {
        $this->validate(['password' => $this->currentPasswordRules()]);

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', Auth::id())
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->reset('password');
        $this->showConfirmation = false;

        Flux::toast(variant: 'success', text: __('Other browser sessions have been signed out.'));
    }

    /**
     * @return array<int, array{id: string, device: string, ip: string, last_active: string, last_active_at: string, is_current: bool}>
     */
    private function sessions(): array
    {
        $currentId = session()->getId();
        $timezone = Auth::user()->effectiveTimezone();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', Auth::id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $session) use ($currentId, $timezone): array {
                $agent = new UserAgent($session->user_agent);

                return [
                    'id' => (string) $session->id,
                    'device' => __(':browser on :platform', [
                        'browser' => __($agent->browser()),
                        'platform' => __($agent->platform()),
                    ]),
                    'ip' => (string) ($session->ip_address ?? __('Unknown')),
                    'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                    // Timestamp absoluto no fuso escolhido pelo usuário: é o
                    // que dá efeito visível à preferência de timezone.
                    'last_active_at' => Carbon::createFromTimestamp($session->last_activity)
                        ->setTimezone($timezone)
                        ->format('d/m/Y H:i'),
                    'is_current' => (string) $session->id === $currentId,
                ];
            })
            ->all();
    }
}; ?>

<section class="mt-12">
    @if ($this->supported())
        <flux:heading>{{ __('Browser sessions') }}</flux:heading>
        <flux:subheading>{{ __('Manage and sign out your active sessions on other browsers and devices') }}</flux:subheading>

        <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm">
            <div class="border rounded-lg border-zinc-200 dark:border-zinc-700 overflow-hidden">
                @foreach ($sessions as $session)
                    <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                        <div class="flex items-center gap-4">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.computer-desktop class="size-5 text-zinc-500 dark:text-zinc-400" />
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-2.5">
                                    <p class="font-medium tracking-tight">{{ $session['device'] }}</p>
                                    @if ($session['is_current'])
                                        <flux:badge size="sm" color="green">{{ __('This device') }}</flux:badge>
                                    @endif
                                </div>
                                <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                    {{ $session['ip'] }}
                                    <span class="opacity-50 mx-1">/</span>
                                    <span title="{{ $session['last_active_at'] }}">{{ __('Last active :time', ['time' => $session['last_active']]) }}</span>
                                    <span class="opacity-50 mx-1">/</span>
                                    {{ $session['last_active_at'] }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-start">
                <flux:button
                    variant="danger"
                    wire:click="$set('showConfirmation', true)"
                    data-test="logout-other-sessions-button"
                >
                    {{ __('Sign out other sessions') }}
                </flux:button>
            </div>
        </div>

        <flux:modal
            name="logout-other-sessions-modal"
            class="max-w-md md:min-w-md"
            wire:model="showConfirmation"
        >
            <form wire:submit="logoutOtherSessions" class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Sign out other sessions') }}</flux:heading>
                    <flux:text>
                        {{ __('Enter your password to confirm you want to sign out of your other browser sessions across all devices.') }}
                    </flux:text>
                </div>

                <flux:input
                    wire:model="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    viewable
                />

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="$set('showConfirmation', false)" type="button">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button variant="danger" type="submit">
                        {{ __('Sign out other sessions') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
