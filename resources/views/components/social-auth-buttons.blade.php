@props([
    'separator' => __('Or continue with email'),
])

@php
    // Só aparece o provedor que está habilitado E configurado: um botão que
    // leva a 404 por falta de credencial é pior que botão nenhum.
    $providers = collect(config('social.providers', []))
        ->filter(fn (array $provider, string $driver): bool => is_string(config("services.{$driver}.client_id")));
@endphp

@if ($providers->isNotEmpty())
    <div class="flex flex-col gap-3">
        @foreach ($providers as $driver => $provider)
            <flux:button
                :href="route('social.redirect', $driver)"
                variant="outline"
                class="w-full"
                data-test="social-{{ $driver }}"
            >
                {{ __('Continue with :provider', ['provider' => $provider['label']]) }}
            </flux:button>
        @endforeach

        {{-- O separador fecha o bloco de alternativas e apresenta o que vem a
             seguir (o formulário de email) — acima dos botões ele lia como um
             "ou" órfão entre o cabeçalho e o Google. --}}
        <div class="mt-1 flex items-center gap-4">
            <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
            <span class="text-xs tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ $separator }}</span>
            <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
        </div>
    </div>
@endif
