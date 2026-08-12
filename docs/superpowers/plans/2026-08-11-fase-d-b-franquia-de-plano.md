# Fase D-b — Franquia de plano por check (plano↔check + gating) — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um plano pode incluir checks com **franquia mensal** (X por período) ou **ilimitado**; o `ExecutarCheck` consome a franquia antes de cair no crédito.

**Architecture:** Um model `PlanCheck` (plano↔check↔quota, catálogo global) e um resolver `Franquia` que, para um time e um check, resolve o plano efetivo (`TeamBilling::plan()`), acha a inclusão, e mede o uso do período contra a quota. O uso é **derivado** do histórico de `CheckExecution` (execuções bem-sucedidas desde o início do período pago) — não há tabela de contador separada, então toda franquia é automaticamente contável. O `ExecutarCheck` ganha a ordem de resolução: incluído+ilimitado → grátis; incluído+dentro da franquia → grátis (a execução conta); franquia esgotada → crédito (se o check tiver preço) ou bloqueia; não incluído → crédito.

**Nomes atuais (pós-rename):** módulo `Modules\CheckerApi`, models `Check`/`CheckExecution` (tabelas `checks`/`check_executions`, coluna `check_id`), action de composição `App\Actions\CheckerApi\ExecutarCheck`.

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; docblocks inglês, prosa/comentários português.
- **Branch `main`** (sem novas branches). **Testes via Sail** (`./vendor/bin/sail artisan test --compact <path>`; suíte `--exclude-testsuite=Browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint no host.
- Dinheiro em centavos. Franquia mede **execuções bem-sucedidas** (`outcome = success`) — `no_data`/`failed` não consomem franquia, coerente com "só cobra no resultado útil".
- Model de domínio escopado por `BelongsToTenant`; `PlanCheck` é **catálogo global** (como `Check`/`Plan`) → na allowlist `UNSCOPED_MODELS` com justificativa.
- Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## Decisão — período da franquia
"Franquia mensal, zera a cada ciclo pago." O período de contagem é:
- Time com assinatura ativa (`Subscriptions::active`): desde `current_period_start` (renovar zera a franquia junto).
- Time sem assinatura (gratuito/trial): `now()->startOfMonth()` (mês corrente), para o caso de o plano gratuito incluir algum check com franquia.

## Decisão — o "contador primeiro" no modelo derivado
O spec falava em registrar um contador por check no padrão `ResourceCounters`. Na prática o uso da franquia é **contagem genérica de execuções por período**, que serve a qualquer check sem registro por-check — então a invariante "franquia sem contador não se aplica" é satisfeita estruturalmente: toda franquia é contável porque toda execução é gravada. Não se cria abstração de contador por check (regra dos 3 usos).

## File Structure
```
app/Models/PlanCheck.php                      # plano↔check↔quota (global)
database/migrations/*_create_plan_checks_table.php
database/factories/PlanCheckFactory.php
app/Support/Billing/Franquia.php              # resolver: for(Team, Check): ?FranquiaStatus
app/Support/Billing/FranquiaStatus.php        # value object (quota, used, remaining, isUnlimited, hasRemaining)
modules/CheckerApi/Exceptions/FranquiaExhaustedException.php
app/Actions/CheckerApi/ExecutarCheck.php      # ordem de resolução franquia→crédito
tests/Feature/Billing/FranquiaTest.php
tests/Feature/CheckerApi/{ExecutarCheckTest,CobrancaCheckTest}.php  # +casos de franquia
composer.json / spec                          # mutation + docs
```

---

### Task 1: Model PlanCheck
**Files:** `app/Models/PlanCheck.php`; `database/migrations/*_create_plan_checks_table.php`; `database/factories/PlanCheckFactory.php`; modify `app/Support/TenantManager.php` (allowlist); test `tests/Feature/Billing/PlanCheckTest.php`.

**Interfaces:**
- Migration `plan_checks`: `id`; `plan` (string, chave interna do plano); `check_id` (FK→`checks`, cascadeOnDelete); `quota` (unsignedInteger nullable — null = ilimitado); timestamps; `unique(['plan','check_id'])`; index `check_id`.
- `PlanCheck` — `App\Models`, `HasFactory`, **NÃO** `BelongsToTenant` (catálogo global). `#[Fillable(['plan','check_id','quota'])]`. `belongsTo(Check)` via `check()` (import `Modules\CheckerApi\Models\Check`). `isUnlimited(): bool` (`quota === null`). Adicionar `\App\Models\PlanCheck::class` a `UNSCOPED_MODELS` com justificativa (catálogo global plano↔check; o operador define, todos os times leem; resolvido pela camada de billing sem tenant).
- Factory: `plan => 'pro'`, `check_id => Check::factory()`, `quota => 100`. States `unlimited()` (`quota => null`).

- [ ] Migration + model + factory + allowlist. Teste: cria PlanCheck com quota e ilimitado; `isUnlimited()` certo; Arch verde (allowlist). Ver falhar. Commit `feat(billing): PlanCheck liga plano a check com franquia`.

---

### Task 2: Franquia resolver + FranquiaStatus
**Files:** `app/Support/Billing/FranquiaStatus.php`; `app/Support/Billing/Franquia.php`; test `tests/Feature/Billing/FranquiaTest.php`.

**Interfaces:**
- `FranquiaStatus` (final readonly): `quota (?int)`, `used (int)`. `isUnlimited(): bool` (`quota === null`); `remaining(): ?int` (`quota === null ? null : max(0, quota - used)`); `hasRemaining(): bool` (`quota === null || used < quota`).
- `Franquia` (resolvido pelo container): `for(Team $team, Check $check): ?FranquiaStatus`:
  - `$plan = TeamBilling::for($team)->plan();` (plano efetivo).
  - `$pc = PlanCheck::where('plan', $plan->key)->where('check_id', $check->id)->first();` se null → retorna null (plano não cobre este check).
  - `$periodStart = resolve(Subscriptions::class)->active($team)?->current_period_start ?? now()->startOfMonth();`
  - `$used = CheckExecution::where('team_id', $team->getKey())->where('check_id', $check->id)->where('outcome', CheckOutcome::Success->value)->where('created_at', '>=', $periodStart)->count();` (import `Modules\CheckerApi\Enums\ProviderOutcome` — o outcome vem do enum `ProviderOutcome` com case `Success`). Nota: `CheckExecution` é escopado por tenant; a leitura roda no contexto do time (o `ExecutarCheck` já tem tenant setado). Nos testes, setar o tenant antes.
  - `return new FranquiaStatus($pc->quota, $used);`

- [ ] Teste (ver falhar): plano cobre com quota → used conta só successes do período; plano cobre ilimitado → isUnlimited; plano não cobre → null; used respeita `current_period_start` (execução antes do início do ciclo não conta); no_data/failed não contam. Commit `feat(billing): resolver de franquia por check e período`.

---

### Task 3: Gating em ExecutarCheck + FranquiaExhaustedException
**Files:** `modules/CheckerApi/Exceptions/FranquiaExhaustedException.php`; modify `app/Actions/CheckerApi/ExecutarCheck.php`; tests `tests/Feature/CheckerApi/{ExecutarCheckTest,CobrancaCheckTest}.php`.

**Interfaces:**
- `FranquiaExhaustedException` (`Modules\CheckerApi\Exceptions`, RuntimeException): `::for(Check $check): self` ("A franquia do plano para [{slug}] acabou e o check não aceita crédito.").
- `ExecutarCheck` — injetar `App\Support\Billing\Franquia $franquia`. Depois do guard de disponibilidade + resolver o time, ANTES de reservar:
  ```php
  $franquia = $this->franquia->for($team, $check);
  $hold = null;
  if ($franquia instanceof FranquiaStatus && $franquia->hasRemaining()) {
      // plano cobre e há franquia (ilimitado ou dentro do teto) → grátis sob o plano
  } elseif ($check->price_cents > 0) {
      // não coberto / franquia esgotada, mas há preço em crédito → cobra crédito
      $hold = $this->reserveCredit->handle($team, $check->price_cents);
  } elseif ($franquia instanceof FranquiaStatus) {
      // coberto pelo plano, franquia esgotada, sem crédito → bloqueia
      throw FranquiaExhaustedException::for($check);
  }
  // else: não coberto e preço 0 → grátis
  ```
  O resto (execute → record → settle/release) igual. A execução bem-sucedida sob franquia **conta** para o período automaticamente (o `Franquia::for` da próxima chamada vê used+1), sem contador explícito.

- [ ] Casos novos (ver falhar): franquia ilimitada → executa, sem hold, sem cobrança; dentro da franquia → executa grátis e o used incrementa (segunda chamada vê used maior); franquia esgotada + preço>0 → cai para crédito (cobra); franquia esgotada + preço 0 → `FranquiaExhaustedException`, nenhuma execução; não coberto + preço>0 → crédito (como antes); no_data sob franquia não consome (used não sobe). Os casos existentes de crédito seguem verdes (check sem PlanCheck cobrindo). Commit `feat(checker-api): franquia de plano antes do crédito no ExecutarCheck`.

---

### Task 4: Verde total + mutation + docs
- [ ] `composer.json` `test:mutate` `--class`: acrescentar `App\Support\Billing\Franquia`, `App\Actions\CheckerApi\ExecutarCheck` (se já não estiver). `composer check` partido (host: pint/rector/peck/type-coverage; Sail: phpstan + suíte). Atualizar o spec (Fase D-b concluída; overrides por usuário seguem em D-c). Commit `chore(billing): fecha composer check e docs da franquia`.

---

## Self-Review
**Cobertura (D4 do spec — franquia):** plano↔check com franquia/ilimitado → Task 1; resolução do uso por período → Task 2; ordem franquia→crédito→bloqueio no ExecutarCheck → Task 3. ✓ O "contador primeiro" fica satisfeito pela contagem derivada de `CheckExecution` (documentado acima).
**Fora de escopo (D-c):** overrides por usuário (preço custom, liberar, bloquear por usuário). D-b entrega a franquia de plano; o override por usuário lê por cima disso na fase seguinte.
**Aresta conhecida (registrar):** o uso da franquia é contado a partir do histórico de execuções, então duas execuções concorrentes podem ambas passar o teto por 1 (como o double-spend da carteira, mas sem lock aqui). Impacto baixo — é franquia incluída, não dinheiro; o time recebe 1 execução a mais do plano, nunca cobrança errada. Registrar no spec como aceito para o MVP; endurecer (lock/contador) só se virar problema real.
**Consistência de tipos:** `Franquia::for(Team,Check): ?FranquiaStatus`, `FranquiaStatus`, `PlanCheck`, `FranquiaExhaustedException` consistentes entre definição (1-2) e consumo (3). `ExecutarCheck` mantém a assinatura pública `handle(Check, CheckInput, ?User): CheckExecution`. O outcome de sucesso vem de `ProviderOutcome::Success`.
