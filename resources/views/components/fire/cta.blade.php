{{-- O botão-assinatura das telas fire: gradiente de fogo em vez do
     variant="primary" plano do Flux. Texto em canvas (quase-preto), não
     branco: branco sobre o fire-400 (#ff8800) fica em ~2,2:1 de contraste —
     ilegível — enquanto o texto escuro fica entre 6,8:1 e 8:1. Pelo mesmo
     motivo o gradiente para no fire-500, sem o fire-600 escuro, onde o texto
     escuro perderia contraste. --}}
<button
    {{ $attributes->class(['inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-fire-400 to-fire-500 px-6 font-display text-sm font-semibold tracking-wide text-canvas shadow-[0_0_20px_-4px_rgb(255_107_0_/_60%)] transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fire-500 motion-safe:hover:scale-[1.01] motion-safe:active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60']) }}
>
    {{ $slot }}
</button>
