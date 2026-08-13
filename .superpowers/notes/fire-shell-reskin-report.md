# Re-skin: shell + dashboard → legado "fogo" (Fênix Center)

## Status

Concluído. Suíte não-Browser verde, `pint`/`rector`/`phpstan`/`peck`/`type-coverage`
limpos, `npm run build` ok. Commit criado em `main` (ver SHA abaixo).

## Resumo dos testes

- `sail artisan test --exclude-testsuite=Browser`: **710 passed, 2 skipped** (712 total —
  os 2 skips já existiam antes desta mudança, não relacionados).
- `tests/Feature/Product/DashboardTest.php` isoladamente: **13 passed** (8 pré-existentes
  + 5 novos).
- `composer refactor:check` (host): passou, 0 arquivos alterados.
- `sail composer types:check` (phpstan, via Sail — ver nota abaixo): **0 erros**.
- `composer spell:check` (peck, host): passou.
- `composer types:coverage` (host): **100%**.
- `npm run build`: ok — Orbitron e Rajdhani baixados via `bunny()` e comitados no
  manifest de fontes; `Space Grotesk` removido, sem referências residuais no CSS
  compilado.
- Suíte `Browser` (Playwright): **não pôde rodar neste sandbox** — falha antes de
  navegar para qualquer página ("Playwright is outdated. Please run
  `npm install playwright@latest && npx playwright install`"), em `AuthenticationTest`,
  `SmokeTest` e `TeamCreationTest` igualmente. É uma limitação de ambiente (binário do
  browser não instalado/desatualizado no container), não relacionada a esta mudança —
  confirmado que o pacote `playwright` do `package.json` (^1.62.0) bate com a versão
  reportada por `npx playwright --version` dentro do container.

## O que mudou

### Fontes (`vite.config.js`, `resources/css/app.css`)

- `--font-display` trocou de `'Space Grotesk'` para `'Orbitron'` (títulos, wordmark,
  números de destaque em todo o produto — únicos consumidores de `font-display` já
  eram as telas do checker/dashboard, então o efeito fica contido ao produto).
- Nova fonte `--font-fire-body: 'Rajdhani'`, exposta como utility `font-fire-body` e
  aplicada só no `<body>` de `resources/views/layouts/app/sidebar.blade.php` — o shell
  autenticado do produto. Marketing (`welcome.blade.php`) e auth continuam em
  `--font-sans` (Instrument Sans), sem mudança.
- Nova utility `text-fire-gradient` (texto em gradiente laranja `135deg`, mesmos stops
  do legado) e `animate-flame-pulse` (pulso de escala do ícone de chama, com
  `motion-safe:`).

### Shell (`resources/views/layouts/app.blade.php` + `layouts/app/sidebar.blade.php`)

Reescrita completa, saindo do `flux:sidebar`/`flux:header` do Flux para uma sidebar
fixa própria, fiel à estrutura do `center/dashboard.php`:

- **Sidebar** (250px, fixa, glass `rgba(10,10,15,0.92)` + `backdrop-blur-[30px]`,
  borda `border-fire-500/6`): logo (ícone de chama + wordmark em gradiente Orbitron,
  usa `config('app.name')` — nunca "Fênix"), nav (Dashboard, Executar consulta,
  Carteira, Planos, Perfil, Histórico, Admin condicional a `isSuperAdmin()`), e um
  bloco de usuário no rodapé (avatar com iniciais em gradiente fire, nome, papel no
  time via `teamRole()->label()`, o `<livewire:team-switcher />` existente, e "Sair"
  em vermelho apagado — POST para `route('logout')`, csrf preservado).
- **Mobile**: off-canvas via Alpine (`x-data="{ sidebarOpen: false }"` no `<body>`,
  `:class` alternando `translate-x-0`/`-translate-x-full`, overlay que fecha ao
  tocar fora), com uma barra fina fixa no topo (`lg:hidden`) contendo só o hambúrguer
  e o wordmark — o "top-bar" rico (título + badges + CTA) é conteúdo de cada página,
  não do shell.
- **`flux:main` removido** de `app.blade.php`: dependia do `grid-area` que só existe
  com `flux:sidebar` no ar. Substituído por `<main class="p-6 lg:p-8">` — mesmo
  padding que o Flux aplicava, então `settings/*` e `teams/*` (que não têm padding
  próprio) ficam pixel-idênticos a antes. As páginas do produto (que usam
  `x-fire.glow`) já tinham seu próprio padding: no dashboard eu tirei o padding
  redundante do `x-fire.glow` para não dobrar (ver abaixo); os outros 4 arquivos do
  checker (`executar`/`carteira`/`planos`/`historico`) eu **não toquei** — continuam
  com o padding duplo que já existia antes desta mudança (pré-existente, fora do
  pedido de "manter renderizando").
- Impersonation banner, toasts, `create-team-modal`, `@fluxScripts` e `@fonts`/`@vite`
  preservados intactos.
- `resources/views/layouts/app/header.blade.php` **não foi tocado** — confirmei via
  grep que não é referenciado em lugar nenhum (`component_layout` do Livewire aponta
  para `layouts::app`, i.e. `app.blade.php` → `app/sidebar.blade.php`; `header.blade.php`
  é código morto do starter kit). Reskiná-lo teria zero efeito visível e um custo de
  revisão maior que o benefício; deixei registrado aqui para quem quiser podar depois.

### Dashboard (`resources/views/pages/⚡dashboard.blade.php`)

- **Top-bar**: título "Dashboard" em `text-fire-gradient` + saudação, badge de saldo
  (verde, ícone `banknotes`), badge de plano (laranja, ícone `rectangle-stack`,
  `$this->billing->plan()->name`), e o CTA primário "Executar consulta" — os botões
  antigos para Carteira/Planos/Histórico/Perfil saíram daqui porque agora vivem na
  sidebar (os testes que checavam `assertSee(route(...))` continuam passando, só que
  a string agora vem do link da sidebar em vez do botão do topo).
- **Stats-grid**: os 3 cards existentes (Consultas / Taxa de sucesso / Saldo, `— `
  nunca "0,0%") viraram `grid-cols-[repeat(auto-fit,minmax(200px,1fr))]`, igual ao
  CSS do legado.
- **Plan-timer**: inalterado (`x-fire.plan-timer`).
- **Duas seções "Últimas ..."** novas, lado a lado (`lg:grid-cols-2`): "Últimas
  consultas" (`CheckExecution`, mais recentes 5, badge de outcome reaproveitando as
  cores exatas do `historico.blade.php`) e "Últimas transações" (`WalletTransaction`,
  mais recentes 5, rótulo de tipo e cor de crédito/débito reaproveitando o
  `carteira.blade.php`) — cada uma com estado vazio (ícone `inbox` + texto convite).
  Dois `#[Computed]` novos no componente, `consultasRecentes()`/`transacoesRecentes()`,
  escopados por time via `BelongsToTenant` (mesma convenção do resto da tela — é o
  dashboard do time, não "meu", ao contrário do Histórico que filtra por
  `user_id`).
- Removi o banner genérico "Nenhuma consulta ainda" que existia isolado: as duas
  seções novas já cobrem a mensagem de vazio com mais precisão (e é o que o legado
  fazia — dois "no-data" por tabela, não um banner central). Confirmei por grep que
  nenhum teste dependia do `data-test="dashboard-empty-state"` removido.

### Mapeamento carding → domínio

Sem Gerador, sem estatística de aprovado/reprovado de cartão em lugar nenhum. Nav e
top-bar mapeiam 1:1 para consultas/carteira/planos do produto, como pedido.

## O que eu **não** consegui reproduzir fielmente (e por quê)

- **Glow de fundo full-viewport** (`.bg-fenix` do legado, `position:fixed` atrás de
  tudo, z-index 0): o produto já tinha, de uma fase anterior, o componente
  `x-fire.glow` que aplica o mesmo radial-gradient só dentro da área de conteúdo
  (não atrás da sidebar). Preservei essa decisão já existente em vez de reabrir essa
  arquitetura — está fora do escopo de "shell + dashboard" e teria exigido mexer nos
  outros 4 arquivos do checker também.
- **Padding exato 30px/35px do `.main-content`**: usei `p-6 lg:p-8` (24px/32px,
  escala do Tailwind) por ser o que o `flux:main` já aplicava — prioridade em não
  quebrar `settings/*`/`teams/*` (que dependiam desse valor) sobre bater o pixel exato
  do legado.
- **Crown icon**: heroicons não tem coroa; usei `rectangle-stack` para Planos (badge
  e nav), como o brief já sugeria como alternativa.
- `header.blade.php`: ver nota acima — código morto, não tocado.

## Onde olhar no navegador

- Sidebar em telas ≥1024px: fixa, 250px, aberta sempre. Abaixo de 1024px: some,
  aparece a barra fina com hambúrguer; clicar abre a sidebar por cima do conteúdo com
  overlay escuro atrás (toque fora ou navegue para fechar).
- `/dashboard`: badges de saldo/plano no topo à direita, grid de stats fluido, timer
  do plano, e as duas listas "Últimas..." — crie uma consulta e uma recarga pra ver
  as linhas populadas (em branco elas mostram o estado vazio com ícone de caixa de
  entrada).
- Bloco de usuário no rodapé da sidebar: avatar com iniciais, papel no time, o
  seletor de time (dropdown) e "Sair" em vermelho apagado que acende ao passar o
  mouse/focar.
- Tab pelo teclado pelos links da sidebar e pelo botão de sair — anel laranja (ou
  vermelho no logout) deve aparecer visivelmente no foco.

## Arquivos tocados

- `vite.config.js`
- `resources/css/app.css`
- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/app/sidebar.blade.php`
- `resources/views/pages/⚡dashboard.blade.php`
- `tests/Feature/Product/DashboardTest.php`
