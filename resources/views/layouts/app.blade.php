<x-layouts::app.sidebar :title="$title ?? null">
    <x-impersonation-banner />

    {{-- Substitui `flux:main`: aquele componente depende do grid-area que só
         existe com `flux:sidebar` no ar, e deixamos de usá-lo — a sidebar
         "fogo" é posicionamento fixo próprio, não o grid do Flux. --}}
    <main class="p-6 lg:p-8">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
