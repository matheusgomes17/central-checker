<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-fire-body text-white antialiased" x-data="{ sidebarOpen: false }">
        {{-- Fecha a sidebar off-canvas ao tocar fora dela, no mobile. --}}
        <div
            class="fixed inset-0 z-40 bg-black/60 lg:hidden"
            x-show="sidebarOpen"
            x-transition.opacity
            @click="sidebarOpen = false"
            aria-hidden="true"
        ></div>

        {{-- ===== SIDEBAR ===== --}}
        <aside
            id="fire-sidebar"
            class="fixed inset-y-0 left-0 z-50 flex w-[250px] flex-col overflow-y-auto border-r border-fire-500/6 bg-[rgba(10,10,15,0.92)] px-[18px] py-[25px] backdrop-blur-[30px] transition-transform duration-300 ease-out motion-reduce:transition-none lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            data-test="fire-sidebar"
        >
            <a
                href="{{ route('dashboard') }}"
                wire:navigate
                class="flex flex-col items-center gap-1.5 rounded-lg border-b border-fire-500/10 pb-6 text-center focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fire-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
            >
                <flux:icon.fire class="size-9 shrink-0 text-fire-500 drop-shadow-[0_0_24px_rgba(255,107,0,0.35)] motion-safe:animate-flame-pulse" />
                <span class="text-fire-gradient font-display text-lg font-extrabold tracking-[0.3em] uppercase">
                    {{ config('app.name', 'Laravel') }}
                </span>
            </a>

            @php
                // Mapeamento carding → domínio: cada item aqui é um destino real do
                // produto (consultas/checker + carteira), nunca o gerador de cartão
                // nem qualquer estatística de aprovação/reprovação do dashboard legado.
                $navItems = [
                    ['route' => 'dashboard', 'label' => __('Dashboard'), 'icon' => 'home'],
                    ['route' => 'checker.executar', 'label' => __('Executar consulta'), 'icon' => 'magnifying-glass'],
                    ['route' => 'checker.carteira', 'label' => __('Carteira'), 'icon' => 'wallet'],
                    ['route' => 'checker.planos', 'label' => __('Planos'), 'icon' => 'rectangle-stack'],
                    ['route' => 'profile.edit', 'label' => __('Perfil'), 'icon' => 'user'],
                    ['route' => 'checker.historico', 'label' => __('Histórico'), 'icon' => 'clock'],
                ];
            @endphp

            <nav class="mt-6 flex-1 space-y-1" aria-label="{{ __('Navegação principal') }}">
                @foreach ($navItems as $item)
                    @php $current = request()->routeIs($item['route']); @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        data-test="sidebar-{{ $item['route'] }}"
                        @class([
                            'flex items-center gap-3 rounded-xl border px-4 py-2.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fire-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas',
                            'border-fire-500/10 bg-fire-500/6 text-fire-400' => $current,
                            'border-transparent text-white/40 hover:bg-fire-500/6 hover:text-fire-400' => ! $current,
                        ])
                        @if ($current) aria-current="page" @endif
                    >
                        <flux:icon :name="$item['icon']" class="size-5 shrink-0" />
                        {{ $item['label'] }}
                    </a>
                @endforeach

                @if (auth()->user()->isSuperAdmin())
                    @php $adminCurrent = request()->is('admin*'); @endphp
                    <a
                        href="/admin"
                        data-test="sidebar-admin"
                        @class([
                            'flex items-center gap-3 rounded-xl border px-4 py-2.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fire-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas',
                            'border-fire-500/10 bg-fire-500/6 text-fire-400' => $adminCurrent,
                            'border-transparent text-white/40 hover:bg-fire-500/6 hover:text-fire-400' => ! $adminCurrent,
                        ])
                    >
                        <flux:icon.shield-check class="size-5 shrink-0" />
                        {{ __('Admin') }}
                    </a>
                @endif
            </nav>

            {{-- Bloco do usuário: avatar, nome, papel no time, seletor de time e
                 sair — deliberadamente no fim da sidebar, não junto do logo. --}}
            <div class="mt-6 rounded-2xl border border-fire-500/10 bg-white/2 p-4 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-gradient-to-br from-fire-500 to-fire-600 font-display text-lg font-bold text-white">
                    {{ auth()->user()->initials() }}
                </div>

                <p class="mt-2 truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                <p class="text-xs tracking-widest text-white/40 uppercase">
                    {{ auth()->user()->currentTeam ? (auth()->user()->teamRole(auth()->user()->currentTeam)?->label() ?? __('Membro')) : __('Sem time') }}
                </p>

                <div class="mt-3 border-t border-fire-500/10 pt-3">
                    <livewire:team-switcher />
                </div>

                <form method="POST" action="{{ route('logout') }}" class="mt-3 border-t border-fire-500/10 pt-3">
                    @csrf
                    <button
                        type="submit"
                        data-test="logout-button"
                        class="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-flame-danger/40 transition hover:bg-flame-danger/4 hover:text-flame-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-flame-danger/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
                    >
                        <flux:icon.arrow-right-start-on-rectangle class="size-4" />
                        {{ __('Sair') }}
                    </button>
                </form>
            </div>
        </aside>

        {{-- ===== CONTEÚDO ===== --}}
        <div class="lg:ml-[250px]">
            {{-- Barra mobile: só abre a sidebar off-canvas — o "top-bar" com
                 título e ações (saldo, plano, CTA) é conteúdo de cada página,
                 ver resources/views/pages/⚡dashboard.blade.php. --}}
            <div class="sticky top-0 z-30 flex items-center gap-3 border-b border-fire-500/10 bg-[rgba(10,10,15,0.92)] px-4 py-3 backdrop-blur-[30px] lg:hidden">
                <button
                    type="button"
                    @click="sidebarOpen = ! sidebarOpen"
                    aria-controls="fire-sidebar"
                    :aria-expanded="sidebarOpen"
                    aria-label="{{ __('Abrir menu') }}"
                    data-test="mobile-menu-toggle"
                    class="rounded-lg p-1.5 text-white/70 transition hover:bg-fire-500/6 hover:text-fire-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fire-500/60"
                >
                    <flux:icon.bars-3 class="size-6" />
                </button>

                <span class="text-fire-gradient font-display text-sm font-bold tracking-[0.2em] uppercase">
                    {{ config('app.name', 'Laravel') }}
                </span>
            </div>

            {{ $slot }}
        </div>

        <livewire:create-team-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
