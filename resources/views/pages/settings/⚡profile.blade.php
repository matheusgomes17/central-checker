<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configurações de perfil')] class extends Component {
    use ProfileValidationRules, WithFileUploads;

    public string $name = '';
    public string $email = '';
    public string $timezone = '';
    public string $locale = '';

    public ?TemporaryUploadedFile $avatar = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->timezone = Auth::user()->timezone ?? '';
        $this->locale = Auth::user()->locale ?? '';
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function locales(): array
    {
        return config('accounts.locales', []);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    #[Computed]
    public function avatarUrl(): ?string
    {
        return Auth::user()->avatarUrl();
    }

    /**
     * Remove the current avatar.
     */
    public function deleteAvatar(): void
    {
        $user = Auth::user();

        if ($user->avatar_path === null) {
            return;
        }

        Storage::disk(Config::string('accounts.avatar_disk'))->delete($user->avatar_path);

        $user->forceFill(['avatar_path' => null])->save();

        unset($this->avatarUrl);

        Flux::toast(variant: 'success', text: __('Avatar removed.'));
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id) + [
            'timezone' => $this->timezoneRules(),
            'locale' => $this->localeRules(),
            'avatar' => $this->avatarRules(),
        ]);

        // Campo vazio significa "seguir o padrão da aplicação", que no banco é
        // null — gravar string vazia congelaria o usuário num padrão antigo.
        $validated['timezone'] = $validated['timezone'] === '' ? null : $validated['timezone'];
        $validated['locale'] = $validated['locale'] === '' ? null : $validated['locale'];

        unset($validated['avatar']);

        if ($this->avatar !== null) {
            $disk = Storage::disk(Config::string('accounts.avatar_disk'));

            // O antigo sai depois que o novo entra: se o upload falhar, o
            // usuário não fica sem avatar nenhum.
            $previous = $user->avatar_path;

            $validated['avatar_path'] = $this->avatar->storePublicly('avatars', [
                'disk' => Config::string('accounts.avatar_disk'),
            ]);

            if ($previous !== null) {
                $disk->delete($previous);
            }
        }

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->reset('avatar');
        unset($this->avatarUrl);

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <div class="flex items-center gap-6">
                <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full border border-fire-500/25 bg-zinc-100 dark:bg-zinc-800">
                    {{-- isPreviewable() antes de temporaryUrl(): o Livewire
                         recusa gerar preview de arquivo que não seja imagem, e
                         o upload rejeitado ainda está preso na propriedade
                         quando a validação falha. --}}
                    @if ($avatar?->isPreviewable())
                        <img src="{{ $avatar->temporaryUrl() }}" alt="" class="size-full object-cover">
                    @elseif ($this->avatarUrl)
                        <img src="{{ $this->avatarUrl }}" alt="" class="size-full object-cover">
                    @else
                        <span class="text-lg font-medium text-zinc-500 dark:text-zinc-400">{{ auth()->user()->initials() }}</span>
                    @endif
                </div>

                <div class="space-y-2">
                    <flux:input
                        wire:model="avatar"
                        :label="__('Avatar')"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                    />
                    @if ($this->avatarUrl)
                        <flux:link class="text-sm cursor-pointer" wire:click.prevent="deleteAvatar">
                            {{ __('Remove avatar') }}
                        </flux:link>
                    @endif
                </div>
            </div>

            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <flux:select wire:model="locale" :label="__('Language')">
                <flux:select.option value="">{{ __('Application default') }}</flux:select.option>
                @foreach ($this->locales as $code => $label)
                    <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- Select nativo de propósito: o variant="listbox" com busca é
                 componente do Flux Pro, e este boilerplate assume o free. --}}
            <flux:select wire:model="timezone" :label="__('Timezone')">
                <flux:select.option value="">{{ __('Application default') }}</flux:select.option>
                @foreach ($this->timezones as $identifier)
                    <flux:select.option value="{{ $identifier }}">{{ $identifier }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- A única ação primária da tela leva o CTA de fogo do shell. --}}
            <x-fire.cta type="submit" data-test="update-profile-button">
                {{ __('Save') }}
            </x-fire.cta>
        </form>

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
