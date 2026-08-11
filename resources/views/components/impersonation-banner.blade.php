@php
    $impersonation = resolve(App\Support\Impersonation::class);
    $impersonator = $impersonation->isActive() ? $impersonation->impersonator() : null;
@endphp

@if ($impersonator)
    {{-- Fixo no topo e impossível de fechar, de propósito: a pessoa operando
         precisa ver a todo momento que não está na própria conta, e a que teve
         a conta acessada precisa poder constatar isso numa captura de tela. --}}
    <div
        class="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 bg-amber-500 px-4 py-2 text-center text-sm font-medium text-amber-950"
        data-test="impersonation-banner"
        role="status"
    >
        <span>
            {{ __('You are signed in as :user. Started by :admin.', [
                'user' => auth()->user()?->name,
                'admin' => $impersonator->name,
            ]) }}
        </span>

        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            @method('DELETE')
            <flux:button type="submit" size="xs" variant="outline" data-test="impersonation-stop">
                {{ __('Stop impersonating') }}
            </flux:button>
        </form>
    </div>
@endif
