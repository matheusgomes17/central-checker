# Fase F-4 — Produto: Histórico de consultas + Perfil (fecha a Fase F) — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps usam checkbox (`- [ ]`). Telas usam **frontend-design** (identidade "fogo" de F-1..F-3).

**Goal:** A última sub-fase do produto: uma tela de **Histórico de consultas** (as execuções do próprio usuário, com resultado owner-only e entrada mascarada) na identidade fogo; **ligar o Perfil** (que o boilerplate já entrega em `settings/profile`) à navegação do produto; e uma passada de **pt_BR nos rótulos de status** para o produto ficar 100% em português.

**Architecture:** Uma página Livewire 4 (volt) `checker/historico` no grupo `{current_team}`. O histórico lista `CheckExecution` do time **e do usuário logado** (o escopo de tenant é por time, não por pessoa — então filtra `user_id = Auth::id()`, conforme o padrão de segurança da memória), com paginação. Ver um resultado passado reusa o padrão da tela Executar (F-1): id `#[Locked]`, `execution()` escopado por `user_id`, resultado cifrado mostrado só ao dono, entrada sempre mascarada (LGPD/D9). O Perfil não é reconstruído — a F-4 apenas o torna alcançável no produto (link para `settings/profile`). A passada pt_BR troca os literais em inglês de `SubscriptionStatus::label()` por português (afeta billing/dashboard/planos — o efeito desejado).

**Reaproveitar (existe — consumir):**
- `Modules\CheckerApi\Models\CheckExecution` (tenant-scoped por time; `check()` belongsTo, `user()` belongsTo, `outcome: ProviderOutcome`, `result` `encrypted:array` — **PII, só o dono vê**, `input_masked` array já mascarado, `duration_ms`, `created_at`, `consumed_franquia`).
- `Modules\CheckerApi\Enums\ProviderOutcome` (Success/NoData/Failed).
- Padrão de segurança per-usuário: `resources/views/pages/checker/⚡executar.blade.php` — `#[Locked] public ?int $executionId`, `execution()` com `->where('user_id', Auth::id())`. Ver [[livewire-prop-locked-e-escopo-por-usuario]].
- Perfil pronto: `resources/views/pages/settings/⚡profile.blade.php` (rota `profile.edit`). Nav do produto: onde os links "Carteira"/"Planos" foram adicionados (dashboard) e/ou `layouts/app/sidebar.blade.php`.
- Componentes fogo: `x-fire.{glow,stat}`. Paginação Livewire: `Livewire\WithPagination` (confira como outras listas do projeto pagem, se houver).
- `App\Support\Billing\SubscriptionStatus::label()` — hoje `__('No subscription')` etc. sem catálogo pt_BR.

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; **docblocks inglês**, prosa/comentários português. `resolve()` não `app()`.
- **Branch `main`**. **Testes via Sail** (`--exclude-testsuite=Browser`; Browser à parte; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint `--dirty` no host.
- **Flux é o gratuito** — conferir variante antes.
- **LGPD/D9 (crítico):** a entrada é PII de terceiro. O histórico mostra **só `input_masked`** (já mascarado), nunca o documento cru; o `result` cifrado só aparece para **o dono da execução** (o `user_id` bate com `Auth::id()`). Nunca logar PII.
- **Segurança (memória):** id de execução setado no servidor leva `#[Locked]`; a listagem e o "ver resultado" filtram `user_id = Auth::id()` (tenant é por time, não por usuário). Ver [[livewire-prop-locked-e-escopo-por-usuario]].
- Dinheiro em centavos; reais na tela.
- Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure
```
resources/views/pages/checker/⚡historico.blade.php   # tela Histórico (volt)
routes/web.php                                         # rota checker/historico
resources/views/pages/⚡dashboard.blade.php OU sidebar # links Histórico + Perfil
app/Support/Billing/SubscriptionStatus.php            # rótulos pt_BR
tests/Feature/Product/HistoricoTest.php
tests/Feature/... (billing/dashboard/planos)          # ajustar asserts de status p/ pt_BR
docs/.../spec                                          # D8 concluído; Fase F concluída
```

---

### Task 1: Tela Histórico de consultas (owner-scoped, PII mascarada)

**Files:** create `resources/views/pages/checker/⚡historico.blade.php`; modify `routes/web.php` (rota `checker/historico`, nome `checker.historico`, grupo `{current_team}`; entrada em `RouteAuthorizationTest`); test `tests/Feature/Product/HistoricoTest.php`.

FIRST: invoque **frontend-design** e siga a identidade fogo.

**Interfaces:**
- Rota: `Route::livewire('checker/historico', 'pages::checker.historico')->name('checker.historico')` no grupo `{current_team}`.
- Componente volt (content-only), `use Livewire\WithPagination;`. `mount()` → `abort_unless($this->team() !== null, 404)`. Estado: `#[Locked] public ?int $selectedId = null` (execução aberta para ver o resultado — setada no servidor via `wire:click="ver(id)"`? **Não**: `wire:click` passa o id do cliente, então valide-o pelo escopo — a ação `ver(int $id)` seta `$this->selectedId` só se a execução for do usuário; ou use `#[Locked]` + uma ação que recebe o id e resolve escopado. O ponto: **`selected()` sempre filtra `user_id = Auth::id()`**, então um id de outro usuário resolve null).
  - `#[Computed] execucoes()` → `CheckExecution::query()->where('user_id', Auth::id())->with('check')->latest()->paginate(15)`. **Filtra por usuário** (tenant já filtra por time; isto adiciona o por-pessoa). Nunca `acrossTenants`.
  - Ação `ver(int $id)` → `$this->selectedId = $id;` (o computed abaixo é quem protege).
  - `#[Computed] selected()` → `$this->selectedId === null ? null : CheckExecution::query()->where('user_id', Auth::id())->find($this->selectedId)`. **Owner-only.** Retorna null para id de outro usuário/time.
- View dentro de `<x-fire.glow>`: título + (opcional) `x-fire.stat` com total de consultas do usuário. Lista paginada: cada linha mostra `check->name`, badge de `outcome` (Success verde-neon/`ok`, NoData cinza, Failed `flame-danger`; rótulo PT), a **entrada mascarada** (`input_masked` formatado — ex. "cpf: 123******01"), `created_at`, e um botão "Ver resultado" (`wire:click="ver({{ $e->id }})"`) só quando `outcome === Success` (NoData/Failed não têm resultado útil). Quando `selected()` existe: um painel/modal com o resultado (`selected()->result` — array decifrado, do dono) e a entrada mascarada; **nunca** o documento cru. Estado vazio: convite ("você ainda não rodou consultas — comece agora", link para `checker.executar`). Paginação com os controles do Flux/Livewire (free). Copy PT, voz ativa.

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Product/HistoricoTest.php`; scaffold de membro de time):
  1. `test_the_history_lists_the_users_executions` — cria N `CheckExecution` do usuário (via factory, com `user_id` e o time); a página lista os nomes das consultas e a entrada mascarada; **uma execução de OUTRO usuário do mesmo time NÃO aparece** (escopo por-usuário — veja falhar contra uma query sem `where('user_id')`).
  2. `test_the_raw_document_is_never_shown` — execução com `input_masked` mascarado e `result` contendo um marcador secreto; a lista `assertDontSee` do marcador cru e `assertSee` do trecho mascarado (teste-guarda LGPD, como na resource de Execuções da E-b).
  3. `test_viewing_a_result_shows_it_only_to_the_owner` — usuário A tem uma execução Success; A chama `ver($id)` e vê o `result`; um usuário B do mesmo time que faz `->set('selectedId', $aExecId)` (ou `ver($aExecId)`) **não** vê o resultado de A (`selected()` retorna null / painel ausente). Veja falhar contra `selected()` sem o `where('user_id')`.
  4. `test_no_data_and_failed_have_no_view_button` — execuções NoData/Failed não oferecem "Ver resultado".
  5. `test_empty_state_invites_the_user` — usuário sem execuções vê o convite, não vácuo.
  6. `test_the_page_requires_team_membership` — não-membro → 403/404.
  Veja falhar, implemente, verde. Commit `feat(produto): histórico de consultas do usuário com resultado owner-only`.

---

### Task 2: Rótulos de status em português (pt_BR)

**Files:** modify `app/Support/Billing/SubscriptionStatus.php`; ajustar os testes que asseguram os rótulos em inglês (grep por `'No subscription'`, `'Active'`, `'Trial'`, `'Expiring'`, `'Expired'`, `->status()->label()` em `tests/`), + `peck.json` se algum literal novo for pego.

**Interfaces:**
- `SubscriptionStatus::label()` passa a devolver português: `None → 'Sem assinatura'`, `Trialing → 'Em teste'`, `Active → 'Ativa'`, `Expiring → 'Expirando'`, `Expired → 'Expirada'`. (Literais PT diretos, como o resto do produto; se `__()` for mantido, o texto-chave já é o PT. Confirme se `spell:check` aceita — senão, whitelist mínima no `peck.json`.) `color()`/`needsAttention()` inalterados. Docblocks/comentários seguem inglês/português como já são.
- **Blast radius (esperado):** billing (`settings/⚡billing.blade.php`), dashboard e planos passam a mostrar os rótulos em PT. É o efeito desejado — o produto fica 100% em português. Ajuste os testes que asseguravam o texto inglês (ex. `PlanosTest` que via "No subscription") para o novo texto PT; **não** enfraqueça — só atualize a string esperada.

- [ ] **Veja falhar/ajuste:** rode a suíte e veja quais testes quebram por causa do texto novo (é esperado — eles asseguravam o inglês). Atualize cada assert para o rótulo PT correspondente. Acrescente um teste (ou estenda um) que assegure `SubscriptionStatus::Active->label() === 'Ativa'` e `None->label() === 'Sem assinatura'` (trava o pt_BR). Verde. Commit `feat(produto): rótulos de status da assinatura em português`.

---

### Task 3: Navegação (Histórico + Perfil) + verde total + docs (fecha a Fase F)

- [ ] Adicione os links **Histórico** (`route('checker.historico', $team)`) e **Perfil** (`route('profile.edit')` — o perfil do boilerplate; confira o nome exato da rota) na navegação do produto, no mesmo padrão dos links "Carteira"/"Planos" (grep `route('checker.carteira'` e espelhe), com teste do(s) link(s) visto(s) falhar primeiro. `npm run build` (se possível). `composer check` partido (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + `artisan test --exclude-testsuite=Browser`). Sem `@phpstan-ignore`/baseline. Atualize o spec: no D8, marcar **Perfil** (via settings, ligado no produto) e **Histórico** entregues (Fase F-4) → **D8 completo**. "Ordem de construção": **Fase F concluída** (F-1..F-4) → **todas as fases A–F entregues**. Commit `chore(produto): fecha composer check e docs do histórico e perfil`.

---

## Self-Review
**Cobertura (D8):** "Perfil e Histórico de consultas" → Task 1 (histórico owner-only, PII mascarada) + Task 3 (perfil ligado à navegação, entregue pelo boilerplate). A passada pt_BR (Task 2) fecha a lacuna de idioma que a revisão da F-3 apontou. ✓ D8 completo.
**Fora de escopo:** reconstruir o perfil (o boilerplate já entrega nome/email/timezone/locale/avatar); retenção/expurgo de PII (o job `users:purge`/TTL do D9 é outra frente).
**Risco — PII/segurança (o mais alto):** o histórico filtra `user_id = Auth::id()` na listagem E no `selected()`; teste 1 (execução de outro usuário some) e teste 3 (B não vê o resultado de A) provam o escopo por-usuário; teste 2 é o guarda LGPD (documento cru nunca aparece). Reusa o padrão da tela Executar (F-1) que a revisão já validou.
**Risco — pt_BR blast radius:** mudar o enum afeta billing/dashboard/planos (desejado); os testes que asseguravam inglês são atualizados para PT sem enfraquecer.
**Consistência de tipos:** `CheckExecution` (`user()`, `check()`, `outcome:ProviderOutcome`, `result` owner-only, `input_masked`); `selected()`/`execucoes()` filtram `user_id`; `#[Locked] selectedId`; `SubscriptionStatus::label():string` (agora PT). `x-fire.{glow,stat}` reutilizados.
