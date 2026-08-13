# URLs do produto sem o slug do time — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps usam checkbox (`- [ ]`).

**Goal:** Tirar o `{current_team}` do caminho das rotas do produto — `/{time}/dashboard` vira `/dashboard`, `/{time}/checker/executar` vira `/checker/executar`, etc. — resolvendo o time atual do usuário logado em vez da URL.

**Architecture:** O tenant do web **já** resolve do usuário: `App\Support\TenantManager::current()` cai em `auth()->user()->currentTeam` de forma lazy quando ninguém setou explicitamente. O `{current_team}` na URL e o `switchTeam()` existem só para *trocar* qual time é o atual, não para descobrir o tenant numa requisição normal. Então basta: (1) remover o prefixo `{current_team}` do grupo de rotas; (2) fazer o `EnsureTeamMembership` resolver o time de `$user->currentTeam` em vez do param; (3) o seletor de time (`⚡team-switcher`) já troca via `switchTeam()` + redirect — só remover o param do redirect; (4) atualizar os `route(..., $team)` das views e os caminhos `/{time}/...` dos testes. Nada muda no modelo de tenancy (ADR 0001: base única, escopo por `team_id`) — muda só de onde vem o "time atual" no web (URL → estado do usuário).

**Superfície atual (confirmada):**
- `routes/web.php:13` — `Route::prefix('{current_team}')->middleware(['auth','verified', EnsureTeamMembership::class])->group(...)` com `dashboard` e o grupo `checker/*`.
- `app/Http/Middleware/EnsureTeamMembership.php` — `team()` resolve `$request->route('current_team') ?? $request->route('team')` por slug; `handle()` valida membership + (se `route('current_team')` e time ≠ atual) chama `switchTeam`.
- `app/Http/Middleware/SetTeamUrlDefaults.php` — seta os defaults de URL `current_team` e `team` a partir de `$user->currentTeam->slug`.
- `app/Concerns/HasTeams.php:switchTeam` — atualiza `current_team_id`, seta o relation, chama `TenantManager::set($team)`, e `URL::defaults(['current_team'=>...])`.
- `resources/views/components/⚡team-switcher.blade.php` — `switchTeam($slug)` → `$user->switchTeam($team)` → `redirectRoute('dashboard', ['current_team'=>$team->slug])`; e uma lógica de reescrever `current_team` em URLs (linhas ~70-75).
- **Rotas com `{team}` que NÃO mudam:** `settings/teams/{team}` (`teams.edit`) usa o param `team` de propósito (editar um time específico) — **preservar**. `/admin` (Filament) e o resto de `settings/*` não têm time na URL.
- Views com `route('dashboard'|'checker.*', $team)`: `layouts/app/sidebar`, `pages/⚡dashboard`, `layouts/app/header`, `pages/checker/⚡historico`, `pages/settings/⚡profile`, `welcome`.
- Testes com `route(..., $team)` ou GET `/{slug}/...`: `tests/Feature/Product/*` (Dashboard, Executar, Carteira, Planos, Historico), `tests/Feature/DashboardTest`, `tests/Feature/Teams/TeamInvitationTest`, `tests/Feature/Authorization/*` (ImpersonationService, Impersonation, TeamMembershipMiddleware), `tests/Feature/Auth/*` (Registration, Authentication).

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; **docblocks inglês**, prosa/comentários português. `resolve()` não `app()`.
- **Branch `main`**. **Testes via Sail** (`--exclude-testsuite=Browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint `--dirty` no host.
- **Não** mexer no modelo de tenancy nem em `TenantManager::current()` (o lazy-resolve do `currentTeam` é o que faz isto funcionar). **Não** mexer nas rotas de API/token (`SetTenantFromToken`) nem no `/admin`.
- **Preservar** `settings/teams/{team}` (o param `team` continua explícito ali).
- Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

### Task 1: Rotas sem prefixo + middleware resolve do currentTeam + views + switcher + testes (mudança atômica)

**Files:** modify `routes/web.php`, `app/Http/Middleware/EnsureTeamMembership.php`, `app/Http/Middleware/SetTeamUrlDefaults.php`, `resources/views/components/⚡team-switcher.blade.php`, e as views/testes listados acima; test novo `tests/Feature/Product/TeamlessUrlsTest.php` (ou estender um existente).

**Por que atômica:** rotas, middleware, views e testes têm que mudar juntos — meia mudança deixa a suíte vermelha. É um refactor indivisível; o ciclo de teste é a suíte inteira ficando verde.

**Interfaces / passos:**
- `routes/web.php`: remover `->prefix('{current_team}')`. O grupo fica `Route::middleware(['auth','verified', EnsureTeamMembership::class])->group(function () { Route::livewire('dashboard', 'pages::dashboard')->name('dashboard'); ...checker/* ... })`. Nomes de rota e o resto **inalterados**.
- `EnsureTeamMembership`:
  - `team(Request)`: se houver param de rota `team` (as rotas de settings que passam `{team}`), resolve por slug como hoje; **senão**, retorna `$request->user()?->currentTeam`. (Assim `settings/teams/{team}` segue funcionando e o produto passa a resolver do usuário.)
  - `handle()`: `abort_if(! $user || ! $team || ! $user->belongsToTeam($team), 403)` continua válido (o `currentTeam` é sempre um time do usuário). A checagem de role idem. Remover o bloco `if ($request->route('current_team') && ! isCurrentTeam) switchTeam` — não há mais `current_team` na URL; o `currentTeam` já é o atual. (Para as rotas de settings com `{team}` que queiram trocar o atual, avalie se ainda precisam do switch — provavelmente não; mantenha o comportamento anterior só se algum teste de settings exigir, senão simplifique.)
  - Um usuário **sem** `currentTeam` (aresta): mantenha o `abort 403` atual (o registro já cria um time pessoal como atual; se algum fluxo deixar sem time, 403 é o comportamento de hoje). Não invente redirect novo sem teste.
- `SetTeamUrlDefaults`: o default `current_team` não é mais usado por nenhuma rota — pode remover essa linha e manter só o default `team` (para `settings/teams/{team}`). Deixe um comentário PT do porquê. (Se remover quebrar algo, mantenha ambos — são inofensivos; prefira o mínimo que passe verde.)
- `HasTeams::switchTeam`: a linha `URL::defaults(['current_team' => $team->slug])` fica órfã — pode remover (nenhuma rota usa `current_team`). Mantenha o `TenantManager::set($team)` e o `update(['current_team_id'])` (é o coração da troca). Comentário PT.
- `⚡team-switcher.blade.php`: `redirectRoute('dashboard', ['current_team'=>...])` → `redirectRoute('dashboard')` (sem param). Remover a lógica de reescrever `current_team` em URLs (linhas ~70-75) — não há mais o que reescrever; o mesmo path `/dashboard` passa a servir o novo time atual após o `switchTeam`. Simplificar o componente de acordo.
- Views: em cada arquivo listado, trocar `route('dashboard', $team)`/`route('checker.*', $team)` → `route('dashboard')`/`route('checker.*')` (sem o `$team`). Conferir que nenhum link do produto ainda injeta o slug.
- Testes: em cada arquivo listado, trocar `route(..., $team)` → `route(...)` e qualquer `->get("/{$team->slug}/dashboard")` → `->get('/dashboard')` (idem checker/*). **Não enfraquecer** — só ajustar a URL/route; as asserções de conteúdo/isolamento seguem.

- [ ] **Escreva o teste novo primeiro e veja falhar** (`tests/Feature/Product/TeamlessUrlsTest.php`):
  1. `test_the_dashboard_resolves_the_users_current_team_without_a_slug` — usuário membro (com `currentTeam` setado), dados conhecidos do time; `->get(route('dashboard'))` (que agora é `/dashboard`, sem slug) → 200 e mostra os dados **do time atual do usuário**. Assert que a URL da rota **não** contém o slug do time (`expect(route('dashboard'))->not->toContain($team->slug)` ou que é exatamente `/dashboard`).
  2. `test_the_product_shows_only_the_current_teams_data_without_a_slug` — o usuário tem dois times (A atual, B outro), com dados distintos em cada; em `/dashboard` vê os de A e **não** os de B; após `switchTeam(B)` (via o seletor ou a ação), `/dashboard` passa a mostrar B. (Prova que o tenant resolve do `currentTeam` e a troca funciona sem URL.)
  3. `test_checker_routes_have_no_team_segment` — `route('checker.executar')`, `route('checker.carteira')`, `route('checker.planos')`, `route('checker.historico')` produzem caminhos sem slug de time (ex. começam com `/checker/`).
  Veja (1)/(3) falharem contra as rotas antigas (o `route('dashboard')` sem time hoje depende do default; a asserção de "sem slug no path" falha enquanto o prefixo existir). Aplique o refactor; rode a suíte inteira até verde. Ajuste os testes existentes que quebrarem por causa da URL (é esperado — eles hitavam `/{slug}/...`). Commit `refactor(produto): URLs do produto sem o slug do time`.

---

### Task 2: Verde total + docs

- [ ] `composer check` completo (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + `artisan test --exclude-testsuite=Browser`; e `composer lint:check` repo-wide deve seguir verde). `npm run build` se possível. Sem `@phpstan-ignore`/baseline. Atualize a doc: se o [ADR 0001](docs/adr/0001-tenancy-em-base-unica.md) ou o spec descreve o time na URL, ajuste para "o time atual resolve do usuário (`currentTeam`); a URL do produto não carrega o slug; a troca de time é pelo seletor". Commit `chore(produto): fecha composer check e docs das URLs sem time`.

---

## Self-Review
**Cobertura:** o pedido ("não mostrar o {time} na URL") → Task 1 remove o prefixo e resolve do usuário; Task 2 fecha o verde e a doc. ✓
**Risco — tenant numa requisição sem slug:** `TenantManager::current()` já cai em `currentTeam` (lazy). Teste 1/2 provam que `/dashboard` sem slug resolve o time certo e isola de outro time.
**Risco — troca de time:** passa a ser só pelo seletor (`switchTeam` já atualiza `current_team_id` + tenant); teste 2 prova a troca sem URL. Perda zero de função; para B2C é irrelevante.
**Risco — settings/teams/{team}:** o param `team` é preservado no `team()` do middleware; um teste de settings de time (se existir) segue verde.
**Risco — regressão em testes:** muitos testes hitam `/{slug}/...`; todos atualizados para o caminho limpo, sem enfraquecer as asserções.
**Fora de escopo:** API/token (`SetTenantFromToken`), `/admin`, e o resto de `settings/*` (já sem time na URL).
**Consistência:** `EnsureTeamMembership::team()` agora retorna `currentTeam` quando não há param `team`; `TenantManager::current()` intocado; nomes de rota preservados; `switchTeam` mantém `current_team_id` + `TenantManager::set`.
