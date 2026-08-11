<x-layouts::app.sidebar :title="$title ?? null">
    <x-impersonation-banner />

    <flux:main>
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
