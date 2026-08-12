# Fase F-1 — Produto: tema "fogo", Dashboard e Executar consulta — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) ou superpowers:executing-plans. Steps usam checkbox (`- [ ]`). A implementação das telas usa a skill **frontend-design**.

**Goal:** As duas primeiras telas do produto (Livewire 4 + Flux) na identidade visual "fogo" recriada do legado: um **Dashboard** com stat-cards (consultas, taxa de sucesso, saldo) + timer do plano, e a tela **Executar consulta** — escolher a consulta, preencher o documento, rodar, ver o resultado e o custo. Estabelece o sistema visual que as sub-fases F-2..F-4 reaproveitam.

**Architecture:** O shell do produto (auth, layout com sidebar, settings, teams) **já existe** em Flux+Tailwind v4. Esta fase (1) planta o **tema fogo** como tokens Tailwind v4 (`@theme`) + reaponta o acento do Flux para o laranja, com componentes Blade reutilizáveis (`x-fire.*`); (2) troca o dashboard placeholder por um real; (3) cria a página `Executar consulta` que consome o `ExecutarCheck` que já existe. Páginas são componentes Livewire v4 single-file (volt) em `resources/views/pages/`, no padrão dos `⚡` existentes; rotas no grupo `{current_team}` (tenant do time já setado pelo `EnsureTeamMembership`).

**Identidade visual (brief fixado pelo usuário — "fogo recriado", dark-first):**
- **Cor:** `--fire-500 #ff6b00`, `--fire-600 #cc3300`, `--fire-400 #ff8800` (marca; gradiente `135deg, #ff6b00→#cc3300`); `--ok #00ff88` (sucesso), `--danger #ff4444` (falha), `--warn #ffaa00`. Canvas `#0a0a0f`; card translúcido `rgba(255,255,255,0.02)` com hairline `rgba(255,107,0,0.14)`; glow radial laranja off-center no fundo. Texto branco / muted `rgba(255,255,255,0.55)`.
- **Tipo:** display **Space Grotesk** (wordmark/headings — um webfont, self-hosted via o pipeline do projeto ou `@fontsource`/Google conforme o projeto já faz; **confirme como as fontes são carregadas hoje** em `partials/head.blade.php`/CSS e siga isso); **números em mono** (`ui-monospace` system stack, tabular — zero download) nos stat-cards, saldo e no campo de documento; corpo no `Instrument Sans` já carregado.
- **Assinatura:** o botão primário **"Executar consulta"** em gradiente fogo + a **revelação do resultado "acendendo"** (glow/pulse curto ao concluir, `prefers-reduced-motion` respeitado); o **timer do plano** como barra fina em gradiente fogo. Gaste a ousadia só aqui; o resto quieto.
- **Piso de qualidade:** responsivo até mobile, foco de teclado visível (anel laranja), `prefers-reduced-motion` respeitado, contraste AA (laranja só em acento/borda/texto grande, nunca corpo pequeno; verde-neon ok em label de sucesso).

## Global Constraints
- PHP 8.4; `declare(strict_types=1)` no PHP; **docblocks inglês**, prosa/comentários português.
- **Branch `main`** (sem novas branches). **Testes via Sail** (`./vendor/bin/sail artisan test --compact <path>`; suíte padrão `--exclude-testsuite=Browser`; **Browser** roda com `composer test:browser`, fora da padrão; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint `--dirty` no host. Use `resolve()` (Rector reescreve `app()`).
- **Flux é o gratuito.** Antes de usar variante confira: `flux:select` com `variant="listbox"` ou busca é Pro; idem outras. Fique no free.
- Dinheiro em centavos; exibição em reais (`Number::currency($cents/100, in:'BRL', locale:'pt_BR')`, como `RevenueOverview`).
- Frontend novo pode exigir `npm run build` — se uma mudança de CSS não aparecer, rode/peça o build. Testes de Blade/Livewire não dependem do build de assets.
- **LGPD (D9):** o documento digitado é PII de terceiro. Nunca logar cru; a execução já mascara (`CheckInput::masked()` / `input_masked`). A tela mostra o resultado ao usuário que consultou, mas **não** persiste nada além do que o `ExecutarCheck` já grava.
- Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## Superfície do backend (já existe — consumir, não reescrever)
- `App\Actions\CheckerApi\ExecutarCheck::handle(Check $check, CheckInput $input, ?User $user = null): CheckExecution`. Lança: `CheckUnavailableException` (em manutenção/breaker aberto), `FranquiaExhaustedException`, `CheckBlockedForUserException`, e a de saldo insuficiente do `ReserveCredit` (confirme o nome exato — `InsufficientCreditException`/similar em `Modules\Carteira\Exceptions`). Cada uma vira uma mensagem amigável na tela.
- `Modules\CheckerApi\Support\CheckInput::fromArray(['documento' => $digits])`. **Contrato de input do produto:** um campo `documento` (CPF ou CNPJ). Validação: só dígitos, comprimento 11 (CPF) ou 14 (CNPJ), com verificação de dígito verificador (use uma regra de validação de CPF/CNPJ — confira se o projeto já tem um Rule/helper; senão, valide comprimento + dígitos e deixe o verificador para uma generalização futura, **documentando a escolha**). Não criar schema de input por-check agora (regra dos 3 usos; nenhum provider real existe ainda).
- `Modules\CheckerApi\Models\Check` (`slug,name,description,category,provider_key,price_cents,status`). Consultas disponíveis: `Check::query()->where('status', CheckStatus::Active)` **e** disponíveis pelo breaker — reutilize `Modules\CheckerApi\Support\CheckAvailability::available(Check): bool`.
- `Modules\CheckerApi\Models\CheckExecution` (escopado; `outcome`, `result` cifrado, `input_masked`, `created_at`) — o produto roda no tenant do time, então as consultas do time resolvem sem `acrossTenants`.
- `Modules\Carteira\Models\Wallet::forTeam(Team): Wallet` (`balance_cents`, `available()`); `App\Support\Billing\TeamBilling` (`plan()`, `onTrial()`, `trialEndsAt()`, `subscribed()`, `ended()`; e a assinatura ativa dá `current_period_end` — ver `Subscriptions`/`PlanSubscription` para o timer).
- Padrão volt: `resources/views/pages/settings/⚡billing.blade.php` (mount com `abort_unless` de time/permissão, `#[Computed]`, ações, Pix). `resources/views/dashboard.blade.php` (placeholder a substituir). Rotas: `routes/web.php` grupo `{current_team}` com `Route::view`/`Route::livewire('path','pages::ns.name')`.

## File Structure
```
resources/css/app.css                                  # + tokens fogo, acento Flux laranja, utilitários glow
resources/views/components/fire/stat.blade.php         # stat-card (número mono + label + delta opcional)
resources/views/components/fire/glow.blade.php         # wrapper de fundo com glow radial
resources/views/components/fire/plan-timer.blade.php   # barra fina de período do plano
resources/views/partials/head.blade.php                # + Space Grotesk (se for o caminho de fonte do projeto)
resources/views/pages/⚡dashboard.blade.php             # dashboard real (volt) — ou manter dashboard.blade.php como wrapper
resources/views/pages/checker/⚡executar.blade.php      # Executar consulta (volt)
routes/web.php                                          # rota da tela Executar dentro do grupo {current_team}
tests/Feature/Product/{DashboardTest,ExecutarConsultaTest}.php
tests/Browser/ExecutarConsultaTest.php                 # (opcional) fluxo interativo
docs/.../spec                                           # D8 parcial (F-1)
```

---

### Task 1: Tema "fogo" + componentes base

**Files:** modify `resources/css/app.css`; create `resources/views/components/fire/{stat,glow,plan-timer}.blade.php`; modify `resources/views/partials/head.blade.php` (fonte, se aplicável); test `tests/Feature/Product/FireComponentsTest.php`.

**Interfaces:**
- `resources/css/app.css` — dentro do `@theme`, adicionar tokens (viram utilitários Tailwind): `--color-fire-400/500/600` (#ff8800/#ff6b00/#cc3300), `--color-ok` (#00ff88), `--color-flame-danger` (#ff4444), `--color-flame-warn` (#ffaa00). Reaponte o acento do Flux para o laranja **no contexto do produto** — a forma menos invasiva: sobrescrever `--color-accent`/`--color-accent-foreground` sob a classe `.dark` (o produto é dark-first) para o fire-500 e texto legível; **confirme via search-docs/Flux docs** que reapontar esses tokens muda botões/links do Flux, e não quebre o admin (o admin já usa `Color::Amber` no PanelProvider, independente do CSS do app). Utilitário/base para o glow radial de fundo (uma classe utilitária `.fire-glow` com o `background-image` dos dois `radial-gradient` laranja translúcidos do brief). Comentário `//`/`/* */` em português explicando por que dark-first.
- `x-fire.stat` (`resources/views/components/fire/stat.blade.php`) — props: `label` (string), `value` (string, já formatado), `hint` (string opcional), `tone` (`fire|ok|neutral`, default neutral). Card translúcido, hairline laranja, **número em `font-[ui-monospace]` tabular grande**, label muted em maiúsculas com tracking. `tone=ok` tinge o número de verde-neon; `tone=fire` gradiente fogo. Acessível (contraste, sem depender só de cor).
- `x-fire.glow` (`glow.blade.php`) — wrapper (`{{ $slot }}`) que aplica o fundo `#0a0a0f` + `.fire-glow`. Usado pelo conteúdo das páginas do produto.
- `x-fire.plan-timer` (`plan-timer.blade.php`) — props: `label` (string, ex. "Plano Pro"), `daysLeft` (int|null), `fraction` (float 0..1, quanto do período resta). Barra fina com preenchimento em gradiente fogo (`width: fraction*100%`), texto "expira em N dias" / "sem plano ativo" quando null. `prefers-reduced-motion`: sem animação de preenchimento.

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Product/FireComponentsTest.php`) — teste de render de Blade component (`$this->blade('<x-fire.stat label="Consultas" value="1.240" />')->assertSee('1.240')->assertSee('Consultas')`; idem `tone="ok"` gera a classe/estilo verde; `x-fire.plan-timer` com `daysLeft=12` mostra "12" e com `daysLeft=null` mostra "sem plano ativo"; `x-fire.glow` renderiza o slot). Veja falhar (componentes não existem). Implemente CSS + componentes. `npm run build` se for conferir no navegador (os testes de Blade não precisam). Commit `feat(produto): tema fogo e componentes base (stat, glow, timer)`.

---

### Task 2: Dashboard real

**Files:** create `resources/views/pages/⚡dashboard.blade.php` (volt) e ajustar `routes/web.php` para apontar o dashboard ao componente (ou manter `resources/views/dashboard.blade.php` como casca que inclui o volt — escolha o caminho que respeita o padrão do projeto; **veja como as outras páginas do produto são roteadas** e siga); test `tests/Feature/Product/DashboardTest.php` (há um `tests/Feature/DashboardTest.php` antigo do placeholder — **não apague**; adapte/estenda ou crie o novo e ajuste o antigo se ele passar a falhar por causa da nova tela, documentando no commit).

**Interfaces:**
- Componente Livewire do dashboard (volt), `mount()` com `abort_unless($this->team() !== null, 404)` no padrão do billing. `#[Computed]` para: `consultasNoPeriodo` (`CheckExecution` do time no período do plano/mês — reutilize a lógica de período que a `Franquia` já usa, ou simplesmente últimos 30 dias; **documente a janela**), `taxaSucesso` (Success/total no período; **null → "—"**, nunca 0% falso — mesma honestidade da E-c), `saldo` (`Wallet::forTeam($team)->balance_cents`), e dados do plano (`TeamBilling`: nome do plano, dias restantes, fração do período para o `plan-timer`).
- View: dentro de `<x-layouts::app>` + `<x-fire.glow>`, um grid de 3 `x-fire.stat` (Consultas / Taxa de sucesso / Saldo) e o `x-fire.plan-timer`. Um CTA fogo "Executar consulta" linkando pra tela da Task 3 (`route(...)`). Copy em português, voz ativa, sentence case. Estado vazio ("nenhuma consulta ainda") como convite à ação, não como vácuo.

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Product/DashboardTest.php`): usuário autenticado num time, com N execuções (algumas Success) e um saldo conhecido, acessa `route('dashboard', $team)` → 200, `assertSee` da contagem de consultas, do saldo em reais e do nome do plano; um time sem consultas mostra o estado vazio ("—"/convite), não "0%". Um usuário não-membro → 403/404 (o middleware já garante; um teste confirma). Veja falhar, implemente, verde. Ajuste o `DashboardTest` antigo se necessário (documente). Commit `feat(produto): dashboard com stat-cards e timer do plano`.

---

### Task 3: Executar consulta

**Files:** create `resources/views/pages/checker/⚡executar.blade.php` (volt); modify `routes/web.php` (rota `checker/executar` no grupo `{current_team}`, nome `checker.executar`); test `tests/Feature/Product/ExecutarConsultaTest.php`; (opcional) `tests/Browser/ExecutarConsultaTest.php`.

**Interfaces:**
- Rota: dentro do grupo `{current_team}` (auth+verified+EnsureTeamMembership), `Route::livewire('checker/executar', 'pages::checker.executar')->name('checker.executar')` (confirme a sintaxe do `Route::livewire` + namespace `pages::` como as rotas existentes).
- Componente volt: `mount()` com `abort_unless($this->team() !== null, 404)`. Estado: `?int $checkId` (consulta escolhida), `string $documento`. `#[Computed] consultas()` → `Check::query()->where('status', CheckStatus::Active)->get()` filtradas por `CheckAvailability::available()` (as em manutenção/breaker aberto aparecem marcadas "em manutenção" e desabilitadas, **não** somem — o brief D8 pede o estado visível). Ação `executar()`:
  - Valida: `checkId` presente e disponível; `documento` = dígitos, 11 ou 14 (ver contrato de input). Erros de validação Livewire → mensagens PT.
  - `$check = Check::findOrFail($checkId)`; `$input = CheckInput::fromArray(['documento' => preg_replace('/\D/','',$documento)])`.
  - `try { $execution = resolve(ExecutarCheck::class)->handle($check, $input, auth()->user()); }` e **capture cada exceção de domínio** → `Flux::toast`/mensagem PT específica: manutenção (`CheckUnavailableException`), franquia esgotada (`FranquiaExhaustedException`), bloqueado (`CheckBlockedForUserException`), saldo insuficiente (a do `ReserveCredit`). Nunca vaze a mensagem técnica crua.
  - No sucesso: expõe o resultado (`$execution->outcome`, `$execution->result` — que o dono da consulta pode ver), o custo cobrado (preço do check ou "coberto pelo plano/franquia"), e o saldo atualizado. `outcome === NoData` → "nenhum registro encontrado" (não é erro, não cobrou). `Failed` → "a consulta não pôde ser concluída" (não cobrou).
- View: dentro de `<x-layouts::app>` + `<x-fire.glow>`. Seleção da consulta (cards ou `flux:select` **free**), campo **documento em mono** com `wire:model`, botão primário **fogo** "Executar consulta" (`wire:loading` desabilita e mostra "Executando…"). Área de resultado que **"acende"** ao concluir (glow/pulse curto, `prefers-reduced-motion` off). Custo e saldo visíveis. Aviso LGPD discreto ("o documento é usado só para esta consulta e guardado mascarado"). Estados: em manutenção (badge + botão desabilitado), erro (mensagem clara + como resolver, ex. "saldo insuficiente — recarregue").

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Product/ExecutarConsultaTest.php`) — registre um `FakeProvider` (chave configurável, `execute()` controlável: success/no_data/failed) no `ProviderRegistry` no setUp; crie um `Check` apontando pra ele e o saldo/franquia do time conforme o caso:
  1. `test_a_pagina_carrega_e_lista_consultas_disponiveis` — `Livewire::test(...)->assertOk()` e vê o nome da consulta.
  2. `test_executa_com_sucesso_grava_e_mostra_resultado_e_custo` — fake retorna Success; `->set('checkId', $id)->set('documento','12345678909')->call('executar')->assertHasNoErrors()`; assert `CheckExecution` gravada (outcome Success), o resultado aparece, o custo/saldo atualizado (débito quando cai no crédito).
  3. `test_documento_invalido_nao_executa` — documento curto/ letras → `assertHasErrors(['documento'])`, nenhuma execução.
  4. `test_no_data_nao_cobra_e_mostra_mensagem` — fake NoData → mensagem "nenhum registro", saldo inalterado.
  5. `test_saldo_insuficiente_mostra_mensagem_amigavel` — check com preço > saldo, sem franquia → mensagem PT de saldo insuficiente, nenhuma execução gravada, saldo inalterado (**veja falhar** contra um `catch` ausente — deve estourar 500 sem o tratamento).
  6. `test_consulta_em_manutencao_aparece_desabilitada` — check `Maintenance` (ou breaker aberto) → aparece marcada, `executar()` recusa com mensagem, sem execução.
  7. `test_a_pagina_exige_ser_membro_do_time` — não-membro → 403/404.
  (Opcional) `tests/Browser/ExecutarConsultaTest.php`: fluxo real com JS — escolher consulta, digitar, clicar, ver o resultado acender. Roda em `composer test:browser`, fora da padrão.
  Veja falhar, implemente, verde. Commit `feat(produto): tela de executar consulta com resultado e custo`.

---

### Task 4: Verde total + docs

- [ ] `npm run build` para garantir o CSS novo compila (se o ambiente permitir; senão anote que precisa rodar). `composer check` partido (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + `artisan test --exclude-testsuite=Browser`). Se criou testes Browser, rode `composer test:browser` à parte e relate. Sem `@phpstan-ignore`/baseline. Atualize o spec (`docs/.../2026-08-10-plataforma-de-consultas-design.md`): no D8, marcar **Dashboard** e **Executar consulta** entregues (Fase F-1); Carteira/Recarga, Planos self-service, Perfil/Histórico seguem em F-2..F-4. Na "Ordem de construção", anotar F-1 concluída. Commit `chore(produto): fecha composer check e docs da F-1`.

---

## Self-Review
**Cobertura (D8 parcial):** "Dashboard — stat-cards + timer de plano" → Task 2. "Executar consulta — escolha, formulário validado, resultado, custo, estado de manutenção" → Task 3. O sistema visual "fogo" que o D8 pede → Task 1 (consumido pelas outras). ✓
**Fora de escopo (F-2..F-4):** Carteira/Recarga Pix (o backend `Pagamentos`/`PaymentIntent` já existe — F-2 reaproveita o padrão da página de billing), Planos self-service, Perfil/Histórico, LGPD detalhada de retenção. Esta sub-fase entrega o núcleo (ver + usar) e a identidade visual.
**Risco — identidade visual não é testável por asserção:** os testes cobrem lógica (dados no dashboard, fluxo do executar, exceções) e render dos componentes; a estética é validada visualmente (`npm run build` + navegador) e um teste Browser opcional. Não inventar asserção frágil de CSS.
**Risco — contrato de input:** um campo `documento` (CPF/CNPJ) é o MVP honesto do domínio B2C; sem schema por-check (3 usos, nenhum provider real). Documentado. `CheckInput::fromArray(['documento'=>digits])`.
**Risco — Flux free:** conferir cada variante antes (listbox/busca no select é Pro). Reapontar `--color-accent` não deve quebrar o admin (que usa `Color::Amber` no PanelProvider, à parte do CSS do app) — confirmar.
**Risco — dark-first vs toggle de aparência:** o produto é dark-first; as telas usam superfícies dark explícitas + tokens fogo, sem brigar com o toggle global. Documentar a escolha no CSS.
**Consistência de tipos:** `ExecutarCheck::handle(Check,CheckInput,?User):CheckExecution`; `CheckInput::fromArray(array):self`; `CheckAvailability::available(Check):bool`; `Wallet::forTeam(Team):Wallet`; `TeamBilling` getters. Componentes `x-fire.{stat,glow,plan-timer}` com as props acima, consumidos por Task 2 e 3.
