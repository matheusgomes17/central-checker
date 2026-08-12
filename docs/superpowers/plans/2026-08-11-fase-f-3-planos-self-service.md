# Fase F-3 — Produto: Planos self-service — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps usam checkbox (`- [ ]`). Implementação de telas usa **frontend-design** (identidade "fogo" de F-1/F-2).

**Goal:** A tela **Planos** do produto: ver o plano atual + estado, comparar os planos pagos **mostrando as consultas incluídas (franquia)** de cada um, e **assinar/renovar via Pix** (QR + copia-e-cola) — na identidade fogo, reaproveitando o `PurchasePlan` que já existe.

**Architecture:** Uma página Livewire 4 (volt) `checker/planos` no grupo `{current_team}`. A assinatura chama `App\Actions\Billing\PurchasePlan` (abre `PaymentIntent` pendente purpose=Plan com o preço do plano, pede o Pix ao gateway) — **não ativa a assinatura**; quem ativa é o webhook `deposit.paid` (já implementado). Espelha o fluxo `subscribe()` da página de billing (`settings/⚡billing.blade.php`), incluindo a **confirmação de downgrade** quando o plano escolhido é menor que o uso atual, mas numa tela **de produto** focada em escolher plano (a página de settings/billing continua para a gestão detalhada — isto é uma segunda porta para a mesma action, não duplicação de lógica). Diferencial B2C: cada card de plano lista as **consultas incluídas** com a franquia, lido de `PlanCheck` (catálogo global plano↔check↔quota).

**Reaproveitar (existe — consumir, não reescrever):**
- `resolve(\App\Actions\Billing\PurchasePlan::class)->handle(\App\Models\Team $team, \App\Support\Billing\Plan $plan, \Modules\Pagamentos\Support\PayerData $payer): \Modules\Pagamentos\Models\PaymentIntent`. Lança `InvalidArgumentException` se `$plan->price <= 0` (plano grátis não se compra). Volta `PaymentIntent` com `copy_paste` + `qr_code`.
- `\App\Support\Billing\Plan`: `Plan::purchasable(): array<string,Plan>` (planos pagos); `->key`, `->name`, `->price` (centavos), `->periodDays`, `->limits`, `->features`, `->limit('members')`.
- `\App\Support\Billing\TeamBilling::for($team)`: `plan()`, `status(): SubscriptionStatus`, `subscribed()`, `onTrial()`, `trialEndsAt()`, `onGracePeriod()`, `ended()`. `\App\Support\Billing\SubscriptionStatus`: `label()`, `color()`, `needsAttention()`.
- **Consultas incluídas por plano:** `\App\Models\PlanCheck::query()->where('plan', $plan->key)->with('check')->get()` (PlanCheck é catálogo global, **não** tenant-scoped — lê sem escopo). Cada um: `->check->name`, `->quota` (null = ilimitado) / `->isUnlimited()`.
- Confirmação de downgrade: espelhe `wouldLeaveTeamOverTheCeiling($plan)` da página de billing (compara `$plan->limit('members')` com `TeamBilling::usage('members')['used']`).
- Permissão: `App\Enums\TeamPermission::ViewBilling` (ver) e `ManageBilling` (assinar), como billing.
- UI do Pix (espelhar): `settings/⚡billing.blade.php` (`subscribe`, render `<img data:image/png;base64>` + bloco mono, confirmação de downgrade via `flux:callout`). Componentes fogo de F-1: `x-fire.{glow,stat,plan-timer}`.

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; **docblocks inglês**, prosa/comentários português.
- **Branch `main`**. **Testes via Sail** (`--exclude-testsuite=Browser`; Browser à parte; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint `--dirty` no host. `resolve()` não `app()`.
- **Flux é o gratuito** — conferir variante antes.
- Dinheiro em centavos; reais na tela (siga a tela vizinha: `number_format($cents/100, 2)`).
- **Segurança (memória):** propriedade Livewire com **id/estado setado no servidor** (o intent do Pix, o `confirming` de downgrade) — se for id, `#[Locked]`; a escolha de plano vem de `wire:click` com a chave, validada contra `Plan::purchasable()` (nunca confie na string crua). Dado é do time (todos veem o plano do time). Ver [[livewire-prop-locked-e-escopo-por-usuario]].
- **Assinar não ativa o plano** — só o webhook ativa. A tela nunca escreve assinatura; só chama `PurchasePlan` e lê.
- Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure
```
resources/views/pages/checker/⚡planos.blade.php     # tela Planos (volt)
routes/web.php                                        # rota checker/planos no grupo {current_team}
resources/views/pages/⚡dashboard.blade.php OU sidebar # link "Planos"
tests/Feature/Product/PlanosTest.php
docs/.../spec                                          # D8 parcial (F-3)
```

---

### Task 1: Tela Planos — plano atual + comparação + assinar via Pix

**Files:** create `resources/views/pages/checker/⚡planos.blade.php`; modify `routes/web.php` (rota `checker/planos`, nome `checker.planos`, grupo `{current_team}`; entrada em `RouteAuthorizationTest` como as outras); test `tests/Feature/Product/PlanosTest.php`.

FIRST: invoque a skill **frontend-design** e siga a identidade fogo.

**Interfaces:**
- Rota: `Route::livewire('checker/planos', 'pages::checker.planos')->name('checker.planos')` no grupo `{current_team}`.
- Componente volt (content-only): `mount()` → `abort_unless($this->team() !== null, 404)` + `abort_unless(hasTeamPermission(ViewBilling), 403)`. Estado: `#[Locked] public ?int $intentId = null` (id do Pix aberto, setado no servidor); `public ?string $confirming = null` (chave do plano aguardando confirmação de downgrade — setado só pelo servidor no fluxo; validar sempre contra `Plan::purchasable()`).
  - `#[Computed] billing()` → `TeamBilling::for($this->team())`.
  - `#[Computed] canManage()` → `hasTeamPermission(ManageBilling)`.
  - `#[Computed] planos()` → `Plan::purchasable()`.
  - `#[Computed] incluidas()` → `array<string, Collection<PlanCheck>>` por chave de plano: para cada plano em `planos()`, os `PlanCheck::where('plan',$key)->with('check')->get()` (catálogo global). Usado no card para listar "consultas incluídas" (nome + "N/mês" ou "ilimitado").
  - `#[Computed] intent()` → resolve `PaymentIntent` do `intentId` (tenant-scoped basta).
  - Ação `assinar(string $key)`: `abort_unless($this->canManage, 403)`. `$plan = Plan::purchasable()[$key] ?? abort(404)` (valida a chave contra os planos compráveis — nunca a string crua). Se `wouldLeaveTeamOverTheCeiling($plan)` e `$this->confirming !== $key` → `$this->confirming = $key; return;` (pede confirmação). Senão: `$this->confirming = null; $intent = resolve(PurchasePlan::class)->handle($this->team(), $plan, PayerData::for($user->name, $user->email)); $this->intentId = $intent->id;` + toast. (Não capturar `InvalidArgumentException` de preço ≤ 0 porque `purchasable()` já exclui o grátis — mas o `?? abort(404)` protege chave inválida.)
- View dentro de `<x-fire.glow>`: (a) card do **plano atual** — nome, badge de `status()` (cor/label), `x-fire.plan-timer` (reusar de F-1; se não houver assinatura ativa, `:daysLeft=null`), callout de trial/expirando quando `needsAttention()`; (b) grid dos **planos compráveis** — cada card: nome, preço/mês, **consultas incluídas** (lista de `incluidas()[$key]`: nome + franquia/ilimitado), botão fogo "Assinar :plano" / "Renovar :plano" (plano atual mostra badge "Plano atual", sem botão); (c) `flux:callout` de confirmação de downgrade quando `confirming` (espelha billing: "este plano é menor que o uso atual", botões "Trocar mesmo assim" / "Cancelar"); (d) bloco do Pix quando `intent()` — `<img data:image/png;base64>` + copia-e-cola mono + botão copiar (Alpine, free). Copy PT, voz ativa. Só `canManage` vê os botões de assinar.

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Product/PlanosTest.php`; scaffold de membro de time; **fake do gateway** como F-2/CreateRechargeTest — sem rede):
  1. `test_the_page_shows_the_current_plan_and_status` — membro vê o nome do plano atual (free por padrão) e o badge de status.
  2. `test_the_page_lists_purchasable_plans_with_included_checks` — cria `PlanCheck` ligando um plano (ex. 'pro') a um `Check` com quota; a tela mostra o nome da consulta incluída e a franquia (ex. "100/mês") no card do Pro; um plano ilimitado mostra "ilimitado".
  3. `test_subscribing_opens_a_plan_payment_intent_and_shows_pix` — `->call('assinar','pro')->assertHasNoErrors()`; assert `PaymentIntent` (purpose Plan, plan 'pro', amount = preço do Pro, Pending) e o copia-e-cola na tela. **A assinatura NÃO é ativada** (nenhuma `PlanSubscription` ativa criada — só o webhook ativa).
  4. `test_subscribing_requires_manage_billing` — membro só com ViewBilling → `assinar` dá 403.
  5. `test_a_downgrade_asks_for_confirmation_first` — (se aplicável ao limite 'members') time acima do teto do plano alvo → primeira chamada de `assinar` seta `confirming` e **não** abre intent; segunda chamada (confirmada) abre. (Se difícil montar o cenário de teto em B2C, cobrir ao menos que `confirming` é setado e que uma chave inválida dá 404.)
  6. `test_an_invalid_plan_key_is_rejected` — `->call('assinar','inexistente')` → 404 (ou erro), nenhum intent.
  7. `test_the_page_requires_team_membership` — não-membro → 403/404.
  Veja falhar, implemente, verde. Commit `feat(produto): tela de planos self-service com assinatura via Pix`.

---

### Task 2: Verde total + docs + navegação

- [ ] Adicione o link **Planos** na navegação do produto (sidebar/dashboard, no mesmo padrão do link "Carteira" de F-2 — grep `route('checker.carteira'` e espelhe), com teste do link visto falhar primeiro. `npm run build` (se possível). `composer check` partido (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + `artisan test --exclude-testsuite=Browser`). Sem `@phpstan-ignore`/baseline. Atualize o spec: no D8, marcar **Planos self-service** entregue (Fase F-3); Perfil/Histórico seguem em F-4. "Ordem de construção": F-3 concluída. `test:mutate` provavelmente inalterado (páginas Livewire fora da lista) — não mexer sem o brief pedir. Commit `chore(produto): fecha composer check e docs dos planos`.

---

## Self-Review
**Cobertura (D8):** "Planos — agora self-service via VirtualPay" → Task 1 (comparar + assinar/renovar via Pix), com o diferencial B2C das consultas incluídas por plano. ✓
**Fora de escopo (F-4):** Perfil, Histórico de consultas.
**Risco — assinar não ativa:** a tela só chama `PurchasePlan` e lê; a ativação é do webhook. Teste 3 prova que nenhuma assinatura ativa nasce ao abrir o Pix. Sem duplicar a lógica de ativação.
**Risco — segunda porta para planos:** a página settings/billing continua existindo; a tela de produto é uma segunda entrada para `PurchasePlan`, não uma reimplementação. Ambas chamam a mesma action. Documentado.
**Risco — chave de plano crua:** `assinar($key)` valida `$key` contra `Plan::purchasable()` (`?? abort(404)`), nunca confia na string; `confirming` é do servidor. Teste 6 cobre.
**Risco — permissão:** ver = ViewBilling, assinar = ManageBilling. Teste 4 cobre.
**Risco — gateway em teste:** fake/bind como F-2, sem rede.
**Consistência de tipos:** `PurchasePlan::handle(Team,Plan,PayerData):PaymentIntent`; `Plan::purchasable():array<string,Plan>`; `PlanCheck::isUnlimited():bool`; `TeamBilling`/`SubscriptionStatus` getters; QR PNG base64 no `<img data:>`. `x-fire.plan-timer` reutilizado de F-1.
