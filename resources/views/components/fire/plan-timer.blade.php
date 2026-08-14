@props([
    'label',
    'daysLeft' => null,
    'fraction' => 0,
])

@php
    $percentage = max(0, min(1, (float) $fraction)) * 100;
@endphp

<div {{ $attributes->class(['w-full']) }}>
    <div class="flex items-center justify-between text-xs text-white/55">
        <span class="font-medium tracking-wide uppercase">{{ $label }}</span>

        <span>
            @if (is_null($daysLeft))
                {{ __('sem plano ativo') }}
            @else
                {{ trans_choice('expira em :count dia|expira em :count dias', $daysLeft, ['count' => $daysLeft]) }}
            @endif
        </span>
    </div>

    {{-- `motion-safe:` deixa o preenchimento aparecer de uma vez sob
         prefers-reduced-motion, em vez de animar até a largura final. --}}
    <div
        class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-white/5"
        role="progressbar"
        aria-valuenow="{{ (int) round($percentage) }}"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label="{{ $label }}"
    >
        <div
            class="h-full rounded-full bg-gradient-to-r from-fire-500 to-fire-600 motion-safe:transition-[width] motion-safe:duration-500"
            style="width: {{ $percentage }}%"
        ></div>
    </div>
</div>
