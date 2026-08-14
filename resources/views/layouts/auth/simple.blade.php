<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    {{-- Mesmo casco das telas do produto (canvas + glow), mas o corpo continua
         em --font-sans: a fonte Rajdhani (font-fire-body) é só do shell
         autenticado — ver o comentário em resources/css/app.css. --}}
    <body class="min-h-screen bg-canvas text-white antialiased fire-glow">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a
                    href="{{ route('home') }}"
                    class="flex flex-col items-center gap-1.5 rounded-lg text-center focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fire-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
                    wire:navigate
                >
                    <flux:icon.fire class="size-10 shrink-0 text-fire-500 drop-shadow-[0_0_24px_rgba(255,107,0,0.35)] motion-safe:animate-flame-pulse" />
                    <span class="text-fire-gradient font-display text-lg font-extrabold tracking-[0.3em] uppercase">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="flex flex-col gap-6 rounded-xl border border-fire-500/14 bg-white/2 p-6 sm:p-8">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
