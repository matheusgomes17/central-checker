# Fase E-c — Admin: observabilidade (saúde de provider + relatórios) — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar o D7 (e a Fase E): uma tela de **Saúde dos Providers** (estado do circuit breaker por provider, healthcheck manual, reset manual do breaker) e os **relatórios que ainda faltam** — consultas/dia, taxa de sucesso por provider, saldo agregado das carteiras.

**Architecture:** Providers **não são Eloquent** — vêm do `ProviderRegistry` (registro em memória, `keys()`/`resolve()`), e o estado do breaker vive no **Cache** (`CircuitBreaker`). Então "Saúde dos Providers" é a **primeira Page customizada** do painel (não uma Resource), montando uma tabela a partir de um array/coleção de providers registrados, com ações por linha (healthcheck, reset do breaker). Os relatórios são **widgets** no padrão dos existentes (`PlatformOverview`, `RevenueOverview`) — e **MRR/receita já existem** em `RevenueOverview`, então esta fase **não** os reimplementa; adiciona só o que falta. Agregados sobre models tenant-scoped (`CheckExecution`, `Wallet`) rodam com o escopo dropado (`acrossTenants()`/`withoutTenancy`), pelo mesmo motivo da E-b — o painel é global. Ver [[tenant-scope-no-painel-global]].

**Nomes atuais:**
- `Modules\CheckerApi\Support\ProviderRegistry` (`keys(): array<int,string>`, `resolve(string): ProviderContract`, `has(string): bool`) — singleton no container.
- `Modules\CheckerApi\Contracts\ProviderContract` (`key(): string`, `execute(CheckInput): ProviderResult`, `healthCheck(): bool`).
- `Modules\CheckerApi\Support\CircuitBreaker` (`isOpen(string $key): bool`, `recordSuccess`/`recordFailure`; constantes `FAILURE_THRESHOLD=5`, `COOLDOWN_SECONDS=60`; estado no Cache). **Precisa de um `reset(string $key): void` público** (Task 1).
- `Modules\CheckerApi\Models\Check` (`provider_key`, `status: CheckStatus`, `check_id` nas execuções aponta pra cá).
- `Modules\CheckerApi\Models\CheckExecution` (scoped; `check_id`, `outcome: ProviderOutcome` (`Success`/`NoData`/`Failed`), `created_at`; relação `check()` existe desde a E-b).
- `Modules\Carteira\Models\Wallet` (scoped; `balance_cents`).
- `App\Support\Billing\Plan` / `PlanSubscription` — já alimentam `RevenueOverview` (MRR/churn); **não mexer**.
- Painel gated por `User::canAccessPanel()` → `isSuperAdmin()`. Widgets auto-descobertos + listados em `AdminPanelProvider::widgets([...])`.

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; **docblocks inglês**, prosa/comentários português. `#[Override]` como os vizinhos.
- **Branch `main`** (sem novas branches). **Testes via Sail** (`./vendor/bin/sail artisan test --compact <path>`; suíte `--exclude-testsuite=Browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint `--dirty` no host. Use `resolve()` (Rector reescreve `app()`).
- Dinheiro em centavos; exibição em reais (`->money('BRL', divideBy:100)` ou `Number::currency(.../100, in:'BRL', locale:'pt_BR')` como `RevenueOverview`).
- Agregados sobre `CheckExecution`/`Wallet` **dropam o tenant scope** (`acrossTenants()` numa consulta, ou `withoutTenancy(fn () => ...)`), senão o painel conta 0 (fail-closed). Teste no contexto **sem tenant**.
- Estado do breaker vive no **Cache**: nos testes, `Cache::flush()`/estado explícito por provider; não presumir cache limpo entre casos.
- Filament v5 — antes de codar, `search-docs` (`['custom page table', 'table array records', 'stats overview widget', 'page action notification']`, `packages:['filament/filament']`). Espelhe `PlatformOverview`/`RevenueOverview` (widgets) e as Tables de E-a/E-b.
- **Não duplicar MRR/receita** (já em `RevenueOverview`). Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure
```
modules/CheckerApi/Support/CircuitBreaker.php          # + reset(string $key): void
app/Filament/Pages/ProviderHealth.php                  # primeira Page customizada
resources/views/filament/pages/provider-health.blade.php  # se a Page precisar de view própria
app/Support/Reporting/ProviderSuccessRate.php          # cálculo reutilizável (taxa por provider) — se compartilhado entre Page e widget
app/Filament/Widgets/OperationsOverview.php            # consultas/dia + saldo agregado + sucesso global
app/Providers/Filament/AdminPanelProvider.php          # registra o widget novo
tests/Feature/Admin/{ProviderHealthPageTest,OperationsOverviewWidgetTest}.php
docs/.../spec                                           # D7 completo; Fase E concluída
```

---

### Task 1: `CircuitBreaker::reset()` + Page "Saúde dos Providers"

**Files:** modify `modules/CheckerApi/Support/CircuitBreaker.php`; create `app/Filament/Pages/ProviderHealth.php` (+ blade view se necessário); test `tests/Feature/Admin/ProviderHealthPageTest.php`. (Opcional: `app/Support/Reporting/ProviderSuccessRate.php` se decidir compartilhar o cálculo — só se usado aqui **e** no widget da Task 2; senão calcule inline.)

**Interfaces:**
- `CircuitBreaker::reset(string $key): void` — limpa o estado (falhas + aberto). Implementação: os mesmos dois `Cache::forget` do `recordSuccess`, mas como verbo do operador ("resetar o breaker manualmente") em vez de "uma chamada teve sucesso". Docblock inglês explicando que é o gesto manual do operador. (Se preferir, `recordSuccess` pode delegar a `reset`; não crie abstração além disso — regra dos 3 usos.)
- `ProviderHealth extends Filament\Pages\Page` — `protected static UnitEnum|string|null $navigationGroup = 'Operação';`, ícone `Heroicon` (ex. `OutlinedSignal`), título "Saúde dos Providers". Gating: a Page herda o gate do painel (`isSuperAdmin()`); confirme que uma Page customizada respeita `canAccessPanel` (herda do painel) — se precisar, adicione `public static function canAccess(): bool` delegando ao mesmo critério dos resources.
  - **Fonte de dados:** para cada `key` em `resolve(ProviderRegistry::class)->keys()`, monte uma linha com: `key`; `checks` (nº de `Check` com aquele `provider_key`); `breaker` (`resolve(CircuitBreaker::class)->isOpen($key)` → "Aberto"/"Fechado"); `success_rate_7d` (taxa de sucesso dos últimos 7 dias — ver cálculo abaixo). Exponha isso via um método público da Page (ex. `providerRows(): array` retornando `array<int, array{key:string, checks:int, open:bool, successRate:?float}>`), consumido pela view/tabela.
  - **Cálculo da taxa por provider (7 dias):** `CheckExecution::acrossTenants()->whereHas('check', fn ($q) => $q->where('provider_key', $key))->where('created_at','>=', now()->subDays(7))` — total e `->where('outcome', ProviderOutcome::Success->value)` para os sucessos; `rate = total === 0 ? null : round(success/total*100, 1)`. (Null = "sem dados no período", não 0% — 0% seria mentira quando não houve chamada.) Rode dentro do escopo dropado (`acrossTenants`).
  - **Ações por provider** (como o painel monta ação numa Page: `Filament\Actions\Action` via um método `->actions()`/`getActions()` ou ações de tabela — confirme o caminho v5 via search-docs):
    - `healthcheck` (label "Testar agora"): `resolve(ProviderRegistry::class)->resolve($key)->healthCheck()` → notificação `success` ("Provider respondeu") ou `danger` ("Provider não respondeu"). Não altera estado; só consulta.
    - `resetarBreaker` (label "Resetar breaker", `->requiresConfirmation()`, visível só quando `isOpen($key)` — ou sempre, tanto faz): `resolve(CircuitBreaker::class)->reset($key)` → notificação `success`. Depois o breaker fica fechado.
  - Se for mais simples no v5, use uma `Table` na Page com `->records(fn () => $this->providerRows())` (Filament v5 suporta tabela sobre array/coleção) e ações de linha; senão, uma blade view iterando as linhas com botões que disparam métodos Livewire (`wire:click`) da Page. Escolha o caminho que os docs v5 suportarem de forma limpa; **os testes abaixo fixam o comportamento, não a técnica.**

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Admin/ProviderHealthPageTest.php`; scaffold de superadmin de E-a/E-b; registre um `FakeProvider` no `ProviderRegistry` no setUp para `keys()` não vir vazio — reaproveite o fake dos testes de CheckerApi, com `healthCheck()` controlável):
  1. `test_the_page_loads_and_lists_registered_providers` — registra 1–2 fakes; `Livewire::test(ProviderHealth::class)->assertOk()` e `assertSee(<chave do provider>)`.
  2. `test_it_shows_the_breaker_state` — abre o breaker de um provider (`resolve(CircuitBreaker::class)` — force `recordFailure` até o limite, ou `Cache::put` da chave `open`); renderiza e `assertSee('Aberto')`; para um provider sem falhas, `assertSee('Fechado')`.
  3. `test_the_healthcheck_action_reflects_the_provider_answer` — fake com `healthCheck()` retornando true → `callAction(TestAction::make('healthcheck')...)` e assert notificação de sucesso (`assertNotified` ou o helper v5); um fake com `healthCheck()` false → notificação de falha. (Confirme o helper de asserção de Notification no v5.)
  4. `test_resetting_the_breaker_closes_it` — abre o breaker de um provider, confirma `isOpen()===true`, dispara a ação `resetarBreaker`, `resolve(CircuitBreaker::class)->isOpen($key)` vira `false`. **Veja este teste falhar primeiro** contra uma versão sem `reset()` (ação inexistente / breaker segue aberto).
  5. `test_the_success_rate_reflects_executions` — cria (via factory, `acrossTenants`/tenant setado) N execuções para um check daquele provider nos últimos 7 dias, algumas `Success`, outras `Failed`; assert a taxa exibida (ex. 2 de 4 → "50"). Uma execução com mais de 7 dias **não** conta.
  6. `test_the_page_stays_shut_for_an_ordinary_user` — `actingAs(User::factory()->create())`, `get(<rota da page>)->assertForbidden()`.
  Veja falhar, implemente, verde. Commit `feat(admin): página de saúde dos providers com breaker e healthcheck`.

---

### Task 2: Widget de relatórios — consultas/dia, taxa de sucesso, saldo agregado

**Files:** create `app/Filament/Widgets/OperationsOverview.php`; modify `app/Providers/Filament/AdminPanelProvider.php` (registrar na lista `widgets([...])`); test `tests/Feature/Admin/OperationsOverviewWidgetTest.php`. (Se criou `app/Support/Reporting/ProviderSuccessRate.php` na Task 1 e ele serve aqui, reutilize; senão calcule inline.)

**Interfaces:**
- `OperationsOverview extends Filament\Widgets\StatsOverviewWidget` (como `PlatformOverview`/`RevenueOverview`), `protected ?string $pollingInterval = null;`. `getStats(): array` com:
  - **Consultas (hoje)** — `CheckExecution::acrossTenants()->whereDate('created_at', today())->count()`; `->description` com "7 dias: N" e `->chart($this->executionsPerDay())` (contagem por dia dos últimos 7, no padrão de `signupsPerDay` do `PlatformOverview`, mas com `acrossTenants`).
  - **Taxa de sucesso (30 dias)** — `success/total*100` das execuções dos últimos 30 dias (`acrossTenants`), `Success` sobre o total; `0` base → mostra "—" ou 0 com cuidado (base vazia não é 0% real — descreva "sem consultas" quando total 0). Cor: `>= 90` success, `>= 70` warning, senão danger.
  - **Saldo agregado das carteiras** — `Wallet::acrossTenants()->sum('balance_cents')` formatado em reais; `->description('N carteiras')`. (É o dinheiro pré-pago parado no sistema, passivo — descreva como tal.)
  - (Opcional) **Consultas (30 dias)** como stat separada se ajudar; não obrigatório.
- Registrar em `AdminPanelProvider::widgets([...])` junto de `PlatformOverview`/`RevenueOverview` (ordem: depois de RevenueOverview). Auto-discovery já pega a classe; o array controla presença/ordem no dashboard — siga o padrão existente.
- **Não** recriar MRR/churn/receita: já estão em `RevenueOverview`. Este widget é o "operacional" (uso + caixa), o outro é o "financeiro".

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Admin/OperationsOverviewWidgetTest.php`; superadmin; contexto sem tenant como o painel):
  1. `test_the_widget_renders` — `Livewire::test(OperationsOverview::class)->assertOk()`.
  2. `test_it_counts_executions_across_teams` — cria execuções em **times diferentes** (via factory + tenant, ou `forceCreate`) hoje; assert a contagem "Consultas (hoje)" reflete o total agregado (prova `acrossTenants` — sem ele conta 0). Uma execução de ontem não entra no "hoje".
  3. `test_the_success_rate_is_computed_over_the_window` — cria execuções nos últimos 30 dias com desfechos mistos; assert a % exibida (ex. 3 Success de 4 → "75"). Execução com mais de 30 dias não conta.
  4. `test_the_aggregate_balance_sums_all_wallets` — 2–3 `Wallet::factory()` em times diferentes com saldos conhecidos; assert o saldo agregado exibido em reais é a soma (prova `acrossTenants`). 
  5. `test_the_widget_needs_the_panel` — (se aplicável) o widget herda o gate do painel; um teste garantindo que a montagem exige superadmin, ou pule se o gate já é coberto pela rota do dashboard. (Não force um teste redundante — o gate do painel já é testado; foque nos cálculos.)
  Para asserções de valor num StatsOverviewWidget, `assertSee(<valor formatado>)` no componente renderizado é o caminho simples; confirme o formato (ex. `Number::currency`) e asserte o texto exato. Veja falhar, implemente, verde. Commit `feat(admin): widget de operações — consultas/dia, sucesso e saldo agregado`.

---

### Task 3: Verde total + docs (fecha a Fase E)

- [ ] `composer check` partido (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + `artisan test --exclude-testsuite=Browser`). Sem `@phpstan-ignore`/baseline. Atualize o spec (`docs/.../2026-08-10-plataforma-de-consultas-design.md`): no D7, marcar **Providers** e **Relatórios** entregues (Fase E-c) — todo o D7 fica ✅ (com a ressalva de que MRR/receita já vinham de antes). Na "Ordem de construção", marcar **E concluída**; só **F (frontend)** restante. Commit `chore(admin): fecha composer check e docs da observabilidade`.

---

## Self-Review
**Cobertura (D7 restante):** "Providers — saúde e estado do circuit breaker, healthcheck manual" → Task 1 (+ `reset()` do breaker como gesto do operador). "Relatórios — receita, MRR, consultas/dia, taxa de sucesso por provider, saldo agregado" → MRR/receita **já em `RevenueOverview`** (não duplicar); consultas/dia + taxa de sucesso + saldo agregado → Task 2; taxa **por provider** → coluna na Page da Task 1. ✓ D7 completo.
**Fora de escopo (Fase F):** telas do produto (Livewire+Flux), LGPD na tela do usuário. E-c é a última sub-fase do admin.
**Risco — providers não são Eloquent:** a Page monta a tabela de um array do `ProviderRegistry`; ações operam via `resolve()` do registry/breaker. Primeira Page customizada — confirmar via search-docs o caminho v5 (tabela sobre array + ações, ou blade + métodos Livewire).
**Risco — tenant scope nos agregados:** toda contagem/soma sobre `CheckExecution`/`Wallet` usa `acrossTenants`; cada Task tem um teste com dados de **times diferentes** que só passa com o escopo dropado. Ver [[tenant-scope-no-painel-global]].
**Risco — estado do breaker no Cache:** testes controlam o estado por provider e não presumem cache limpo; a taxa "sem dados" é `null`/"—", nunca 0% falso.
**Consistência de tipos:** `ProviderRegistry::keys():array<int,string>`, `ProviderContract::healthCheck():bool`, `CircuitBreaker::{isOpen,reset}(string):bool|void`, `CheckExecution.outcome:ProviderOutcome`, `Wallet.balance_cents:int`. Widgets estendem `StatsOverviewWidget` como os existentes; a Page estende `Filament\Pages\Page`. MRR permanece em `RevenueOverview`, intocado.
