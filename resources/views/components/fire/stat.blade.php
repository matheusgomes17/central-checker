@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'neutral',
])

@php
    // O tom não pode depender só da cor: cada um também troca o ícone e leva
    // um texto para leitor de tela, então quem não distingue laranja de verde
    // ainda sabe o que o número significa.
    $numberToneClasses = match ($tone) {
        'ok' => 'text-ok',
        'fire' => 'bg-gradient-to-br from-fire-400 to-fire-600 bg-clip-text text-transparent',
        default => 'text-white',
    };

    $accessibleToneLabel = match ($tone) {
        'ok' => 'Indicador positivo',
        'fire' => 'Indicador em destaque',
        default => null,
    };
@endphp

<div {{ $attributes->class(['rounded-xl border border-fire-500/14 bg-white/2 p-5']) }} data-tone="{{ $tone }}">
    <div class="flex items-center gap-1.5 text-xs font-medium tracking-widest text-white/55 uppercase">
        @if ($tone === 'ok')
            <flux:icon.check-circle class="size-3.5 shrink-0 text-ok" />
        @elseif ($tone === 'fire')
            <flux:icon.fire class="size-3.5 shrink-0 text-fire-500" />
        @endif

        <span>{{ $label }}</span>
    </div>

    <p class="mt-2 font-mono text-3xl font-semibold tabular-nums {{ $numberToneClasses }}">
        {{ $value }}

        @if ($accessibleToneLabel)
            <span class="sr-only">({{ $accessibleToneLabel }})</span>
        @endif
    </p>

    @if ($hint)
        <p class="mt-1 text-xs text-white/55">{{ $hint }}</p>
    @endif
</div>
