<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('teams.index')" :current="request()->routeIs('teams.*')" wire:navigate>{{ __('Teams') }}</flux:navlist.item>
            <flux:navlist.item :href="route('notifications.edit')" wire:navigate>{{ __('Notifications') }}</flux:navlist.item>
            <flux:navlist.item :href="route('api-tokens.edit')" wire:navigate>{{ __('API tokens') }}</flux:navlist.item>
            <flux:navlist.item :href="route('webhooks.edit')" wire:navigate>{{ __('Webhooks') }}</flux:navlist.item>
            <flux:navlist.item :href="route('billing.edit')" wire:navigate>{{ __('Billing') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <h2 class="font-display text-sm font-semibold tracking-widest text-white/70 uppercase">{{ $heading ?? '' }}</h2>
        <p class="mt-1 text-sm text-white/55">{{ $subheading ?? '' }}</p>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
