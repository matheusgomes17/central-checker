<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
{{-- Sem @fluxAppearance de propósito: o produto é dark-first (ver
     resources/css/app.css) e o html carrega class="dark" fixa nos layouts. O
     script de aparência do Flux removia a classe conforme a preferência do
     sistema, rendendo componentes claros sobre o canvas escuro. O tema claro
     existe só no painel do operador (Filament). --}}
