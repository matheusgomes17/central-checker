{{-- Casco das telas do produto: canvas quase-preto com o glow radial laranja
     fora de centro. Usado em volta do conteúdo do dashboard e da execução de
     consulta, não dentro de cards individuais. --}}
<div {{ $attributes->class(['min-h-full bg-canvas text-white fire-glow']) }}>
    {{ $slot }}
</div>
