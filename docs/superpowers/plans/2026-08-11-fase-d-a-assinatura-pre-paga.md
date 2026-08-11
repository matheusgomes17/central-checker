# Fase D-a — Assinatura pré-paga via Pix + remoção do Cashier — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir a assinatura Stripe/Cashier por uma assinatura **pré-paga renovável via Pix**, mantendo intacta a fronteira `TeamBilling` de que o resto do app depende, e então **remover o Cashier**.

**Architecture:** Estratégia **substituir-por-dentro, depois podar**. `TeamBilling` é o único ponto que o app consulta para "qual plano / qual estado" — reescrevemos as entranhas dele para ler um model próprio `PlanSubscription` (dirigido pelo Pix), preservando as assinaturas públicas. A compra/renovação de plano reusa o `PaymentIntent` da Fase C com `purpose=plan`; o webhook, ao confirmar um Pix de plano, ativa/estende a assinatura em vez de creditar a carteira. Só quando a nova base passa em teste é que o Cashier sai (controllers, config, migrations, colunas, pacote).

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, o `PaymentIntent`/webhook da Fase C, o `TeamBilling`/`Plan`/`SubscriptionStatus`/`ResourceCounters` existentes.

## Global Constraints

- PHP 8.4; `declare(strict_types=1)`; docblocks em inglês, prosa/comentários em português.
- **Branch `main`** (sem novas branches). **Testes via Sail** (`./vendor/bin/sail artisan test --compact <path>`; suíte `./vendor/bin/sail artisan test --exclude-testsuite=Browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint no host.
- **A fronteira `TeamBilling` (métodos públicos) não muda de assinatura.** Só as entranhas. Se um teste do `TeamBillingTest` precisar mudar de _setup_ (criar `PlanSubscription` em vez de Cashier), as **asserções** de comportamento ficam iguais.
- Dinheiro em centavos; a compra de plano cobra `plan.price` (centavos) via Pix, valor idêntico ao anunciado.
- Todo teste novo/reescrito é visto falhar antes de valer.
- Modelo de estados **pré-pago** (sem dunning/retry, sem read-only por inadimplência): `None → Trialing → Active → Expiring → Expired`. `Expired` = caiu no `default_plan` (gratuito), **não** é read-only.
- Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## Modelo de estados (pré-pago) — o mapeamento

| Antes (Cashier) | Agora (Pix pré-pago) | Como se decide |
|---|---|---|
| `Trialing` | `Trialing` | `team.trial_ends_at` no futuro e sem assinatura paga |
| `Active` | `Active` | `PlanSubscription` com `current_period_end` no futuro |
| `Cancelling` (grace) | `Expiring` | assinatura ativa marcada `canceled_at` (não renova) — ainda tem acesso até `current_period_end` |
| `PastDue` (pagamento falhando) | **removido** | Pix não tem retry; ou pagou (renovou) ou não |
| `Ended` (read-only) | `Expired` | `current_period_end` passou — cai no gratuito, **acesso normal ao gratuito** |
| `None` | `None` | nunca assinou |

`isReadOnly()` passa a devolver **sempre `false`** (não há mais suspensão por inadimplência; o `EnsureTeamIsNotSuspended` vira no-op e sai na poda). `hasAccess()` = `subscribed() || onTrial()` (sem `paymentFailing`).

## File Structure

```
app/Models/PlanSubscription.php                 # nova assinatura (team, plan, período, canceled_at)
app/Support/Billing/Subscriptions.php           # resolve a assinatura ativa + estado de um time
app/Support/Billing/SubscriptionStatus.php      # enum adaptado (Expiring/Expired no lugar de Cancelling/Ended/PastDue)
app/Support/Billing/TeamBilling.php             # entranhas reescritas p/ ler PlanSubscription
app/Support/Billing/Plan.php                    # price_id -> period_days; purchasable = price>0
app/Actions/Billing/ActivatePlan.php            # ativa/estende assinatura (chamada pelo webhook)
app/Actions/Billing/PurchasePlan.php            # cria PaymentIntent purpose=plan (substitui SwapPlan/checkout)
app/Actions/Billing/SwapPlan.php                # removido (a troca vira PurchasePlan)
app/Http/Controllers/VirtualPayWebhookController.php  # ramo purpose=plan -> ActivatePlan
modules/Pagamentos/Enums/PaymentPurpose.php     # +Plan
database/migrations/*_create_plan_subscriptions_table.php
config/billing.php                              # planos: period_days no lugar de price_id
resources/views/pages/settings/⚡billing.blade.php    # comprar/renovar via Pix; histórico local
app/Filament/Resources/Subscriptions/*          # lê PlanSubscription
app/Filament/Widgets/RevenueOverview.php        # MRR de PlanSubscription/PaymentIntent
# --- poda do Cashier (fase final) ---
remove: BillingController, StripeWebhookController, config/cashier.php, migrations Cashier,
        colunas stripe_* em teams, trait Billable, configureBilling(), rotas cashier.*,
        EnsureTeamIsNotSuspended, tests Stripe, laravel/cashier, STRIPE_* no .env.example
docs/poda.md                                    # atualizar a seção de cobrança
```

---

### Task 1: PaymentPurpose ganha `Plan` + PlanSubscription model

**Files:** `modules/Pagamentos/Enums/PaymentPurpose.php` (add `Plan`); `app/Models/PlanSubscription.php`; `database/migrations/*_create_plan_subscriptions_table.php`; `database/factories/PlanSubscriptionFactory.php`; test `tests/Feature/Billing/PlanSubscriptionTest.php`.

**Interfaces:**
- `PaymentPurpose` ganha `case Plan = 'plan';`.
- `PlanSubscription` — colunas: `id, team_id (FK cascade + index), plan (string, a chave do plano), current_period_start (datetime), current_period_end (datetime), canceled_at (datetime nullable), timestamps`. **Não** é `BelongsToTenant`: assinatura é do time, e o webhook a resolve sem tenant (como a antiga `subscriptions` estava em `UNSCOPED_TABLES`). Adicionar `PlanSubscription::class`/tabela à allowlist `UNSCOPED_MODELS`/`UNSCOPED_TABLES` do `TenantManager` **com justificativa** (webhook resolve sem tenant; a relação `team()` já filtra). `belongsTo(Team)`. Casts de datas. `$fillable` sem `team_id` (setar explícito na action, como o webhook faz).
- Métodos de leitura finos: `isActive(): bool` (`current_period_end` futuro), `isExpiring(): bool` (`isActive() && canceled_at !== null`).

- [ ] Migration + model + factory (factory com states `active()`, `expired()`, `canceling()`). Teste: cria uma assinatura ativa/expirada e confere `isActive()`/`isExpiring()`; Arch verde (o model unscoped precisa da justificativa na allowlist). Ver falhar antes. Commit `feat(billing): PlanSubscription e purpose Plan`.

---

### Task 2: SubscriptionStatus adaptado ao pré-pago

**Files:** `app/Support/Billing/SubscriptionStatus.php`; `tests/Unit/SubscriptionStatusTest.php` (adaptar).

**Interfaces:** enum `SubscriptionStatus: string` com casos `None`, `Trialing`, `Active`, `Expiring`, `Expired`. `label()`, `color()`, `needsAttention()` (true para `Expiring` e `Expired` — o time deve renovar; **não** para `Active`/`Trialing`/`None`). Remover `PastDue`, `Cancelling`, `Ended`.

- [ ] Reescrever o enum; adaptar `SubscriptionStatusTest` (mesma ideia: `needsAttention` pede atenção nos estados que pedem, fica quieto nos outros). Ver falhar. Commit `feat(billing): estados de assinatura pré-paga`.

> Cuidado: `TeamBilling::status()` e a UI ainda referenciam os casos antigos — vão compilar quebrado até a Task 3/8. Rode só o teste do enum nesta task; a suíte inteira volta ao verde na Task 3.

---

### Task 3: Subscriptions (resolver) + TeamBilling reescrito por dentro

**Files:** `app/Support/Billing/Subscriptions.php` (novo); `app/Support/Billing/TeamBilling.php` (entranhas); `tests/Feature/Billing/TeamBillingTest.php` (setup por `PlanSubscription`, asserções iguais).

**Interfaces:**
- `Subscriptions` (resolvido pelo container): `active(Team): ?PlanSubscription` (a assinatura com `current_period_end` no futuro, sem tenant scope), `statusFor(Team): SubscriptionStatus`.
- `TeamBilling` — **mesmas assinaturas públicas** (`plan()`, `onTrial()`, `trialEndsAt()`, `subscribed()`, `onGracePeriod()`, `paymentFailing()`, `ended()`, `hasAccess()`, `isReadOnly()`, `status()`, `usage()`, `withinLimit()`, `trialDays()`, `for()`). Entranhas:
  - `subscribed()` = `Subscriptions::active($team) !== null`.
  - `onTrial()` = `team.trial_ends_at` futuro **e** não `subscribed()`.
  - `plan()` = se `subscribed()`, `Plan::find(active->plan)`; senão `Plan::default()`. (Trial dá acesso mas em qual plano? Decisão: trial dá o `default_plan` com features do trial? Mais simples: **trial = acesso ao plano gratuito** — o trial no pré-pago serve para experimentar o produto, e o upgrade é comprar. Se o produto quiser trial de plano pago, é outra decisão; aqui trial = free com prazo. `plan()` no trial retorna `Plan::default()`.)
  - `onGracePeriod()` = `active?->isExpiring() ?? false` (marcado para não renovar, ainda no período).
  - `paymentFailing()` = **`false`** (não existe no Pix).
  - `ended()` = teve assinatura mas nenhuma ativa agora (`! subscribed()` e existe histórico) — ou simplesmente `false` para `isReadOnly`. `isReadOnly()` = **`false`** sempre.
  - `status()` = `Subscriptions::statusFor($team)` (o `match` mora no resolver).
  - `hasAccess()` = `subscribed() || onTrial()`.
- Remover o `use Laravel\Cashier\...` e a constante `SUBSCRIPTION='default'` deste arquivo.

- [ ] Escrever/adaptar o `TeamBillingTest` primeiro (setup cria `PlanSubscription::factory()->active()->create(['team_id'=>..,'plan'=>'pro'])`; trial via `team.trial_ends_at`; expirado via `expired()`), ver falhar; implementar `Subscriptions` + reescrever `TeamBilling`; ver passar. Rodar TAMBÉM `PlanFeatureTest`, `PlanLimitTest`, `OverTheLimitTest`, `ResourceCountersTest`, `SuspensionTest` — todos leem via `TeamBilling` e devem voltar ao verde (adaptando só o setup de assinatura onde criavam via Cashier). Commit `feat(billing): TeamBilling lê PlanSubscription (fim da dependência de Cashier na leitura)`.

> Este é o coração da fase. O objetivo: depois dele, **nada** no app lê o plano/estado via Cashier — só via `TeamBilling`, que agora lê o nosso model.

---

### Task 4: Plan sem Stripe (period_days) + config

**Files:** `app/Support/Billing/Plan.php`; `config/billing.php`; `tests/Feature/Billing/PlanTest.php` (se existir; senão cobrir no TeamBillingTest).

**Interfaces:** `Plan` perde `priceId`; ganha `periodDays (int)`. `purchasable()` = planos com `price > 0` (era `priceId !== null`). `isFree()` = `price === 0`. Remover `forPriceId()` (era Stripe→interno). `config/billing.php`: cada plano troca `price_id` por `period_days` (ex.: `pro => ['price'=>4900,'period_days'=>30,'limits'=>['members'=>20],'features'=>['api']]`); `free` fica `price=0`.

- [ ] Adaptar; ver falhar/verde. Commit `feat(billing): planos pré-pagos com período em dias, sem price_id`.

> Efeito colateral: `SwapPlan` (usa `priceId`/`swap()`), `BillingController` (checkout/portal/invoice) e a página de billing e o Filament ainda referenciam Stripe — quebram a compilação/testes deles. Tasks 5-8 resolvem. Rode nesta task só `PlanTest`/`TeamBillingTest`.

---

### Task 5: ActivatePlan + PurchasePlan (compra via Pix)

**Files:** `app/Actions/Billing/ActivatePlan.php`; `app/Actions/Billing/PurchasePlan.php`; remover `app/Actions/Billing/SwapPlan.php`; tests `tests/Feature/Billing/ActivatePlanTest.php`, `tests/Feature/Billing/PurchasePlanTest.php`; adaptar/remover `SwapPlanTest.php`.

**Interfaces:**
- `ActivatePlan->handle(Team $team, Plan $plan): PlanSubscription` — cria ou ESTENDE a assinatura: se há assinatura ativa do mesmo plano, `current_period_end += period_days`; senão cria nova com `current_period_start=now`, `current_period_end=now+period_days`, `plan=$plan->key`, `canceled_at=null`. Idempotência é do webhook (não daqui). Sem tenant (webhook chama sem contexto) — setar `team_id` explícito, resolver com `withoutTenancy` se necessário.
- `PurchasePlan->handle(Team $team, Plan $plan, PayerData $payer): PaymentIntent` — reusa o gateway: cria um `PaymentIntent` com `purpose=Plan`, `amount_cents=$plan->price`, `reference='PLAN-'.$plan->key.'-'.$team->id.'-'.Str::uuid()`, chama `PaymentGateway::createPixCharge`, guarda external_id/copy_paste. **Não** ativa nada — a ativação é no webhook confirmado. (Precisa guardar qual plano: adicionar `plan` (string nullable) ao `PaymentIntent`, ou derivar da `reference`. Escolha: coluna `plan` nullable no `PaymentIntent` para não parsear reference — migration curta.)

- [ ] Migration curta: `payment_intents.plan` string nullable. `ActivatePlan` + teste (cria assinatura; estende quando já ativa; começa novo período quando expirada). `PurchasePlan` + teste (cria intent purpose=plan com amount=plan.price, guarda o plano, chama o gateway via Http::fake). Ver falhar. Commit `feat(billing): comprar e ativar plano via Pix`.

---

### Task 6: Webhook ramifica por propósito

**Files:** `app/Http/Controllers/VirtualPayWebhookController.php`; test `tests/Feature/Pagamentos/VirtualPayWebhookTest.php` (novos casos plan).

**Interfaces:** no ramo de crédito (após verificar assinatura + achar o intent + travar + checar `Paid`), ramificar por `intent->purpose`:
- `Recharge` → `CreditWallet` (como hoje).
- `Plan` → `ActivatePlan::handle($intent->team, Plan::find($intent->plan))`, e marca o intent `Paid`.
Idempotência a mesma (intent travado + status Paid). Sempre 200.

- [ ] Novo caso de teste: um `deposit.paid` assinado para um intent `purpose=plan` **ativa** a assinatura do time (cria `PlanSubscription` ativa do plano certo) e marca o intent Paid; replay não ativa/estende de novo (idempotente). Ver falhar. Commit `feat(billing): webhook ativa plano no Pix de plano confirmado`.

---

### Task 7: Página de billing — comprar/renovar via Pix

**Files:** `resources/views/pages/settings/⚡billing.blade.php`; rotas em `routes/settings.php` (trocar checkout/portal/invoice Stripe por compra Pix + histórico); test browser/feature conforme já existir.

**Interfaces:** a tela mostra plano atual + `status()`/label; uso vs limite (igual); a lista de planos (`Plan::purchasable()`) com botão "Assinar/Renovar" que chama `PurchasePlan` e mostra o **QR/copia-e-cola** Pix (como a recarga); histórico = `PaymentIntent` do time com `purpose=plan` (no lugar das faturas Stripe ao vivo). Remover "Manage payment" (portal Stripe) e download de fatura Stripe.

- [ ] Adaptar a página + rotas; teste do fluxo (assinar gera intent de plano com QR; o histórico lista pagamentos de plano). Ver falhar. Commit `feat(billing): tela de plano compra/renova via Pix`.

---

### Task 8: Filament — Subscriptions + Receita do novo modelo

**Files:** `app/Filament/Resources/Subscriptions/*`; `app/Filament/Widgets/RevenueOverview.php`; test `tests/Admin/BillingPanelTest.php` (adaptar).

**Interfaces:** o resource lê `App\Models\PlanSubscription` (colunas plan, status via `TeamBilling`, `current_period_end`, `canceled_at`, time). `RevenueOverview`: MRR = soma de `plan.price` das assinaturas ativas (normalizado por período p/ mensal), churn = expiradas nos últimos 30 dias sem renovação. Sem `stripe_status`.

- [ ] Adaptar resource + widget + teste. Ver falhar. Commit `feat(admin): painel de assinaturas lê o modelo pré-pago`.

---

### Task 9: PODA do Cashier

**Files (remover/limpar):** `app/Http/Controllers/BillingController.php`, `StripeWebhookController.php`, `PaymentController` (Cashier); `config/cashier.php`; as 5 migrations Cashier (`create_customer_columns`, `create_subscriptions_table`, `create_subscription_items_table`, +meter) — **substituir** por uma migration que dropa as tabelas `subscriptions`/`subscription_items` e as colunas `stripe_id/pm_type/pm_last_four` de `teams` (manter `trial_ends_at`, que a trial pré-paga usa); trait `Billable` e `@property stripe_id` em `Team`; `configureBilling()`→só `PlanFeature::registerAll()` e `ResourceCounters`, sem `Cashier::useCustomerModel`; `Cashier::ignoreRoutes()` em `register()`; rotas `cashier.webhook`/`cashier.payment` em `routes/web.php` e a exclusão CSRF do path do cashier em `bootstrap/app.php` (manter a do `webhooks/virtualpay`); `EnsureTeamIsNotSuspended` (vira no-op — remover o middleware e o alias `not-suspended`); tests Stripe (`StripeIntegrationTest`, `Billing/WebhookSignatureTest`, `StripeWebhookTest`) removidos; `SuspensionTest` removido ou reduzido (não há mais read-only); `InvoicesTest` reescrito para histórico local; `DemoSeeder::subscriptions()` → cria `PlanSubscription` em vez de Cashier; `STRIPE_*`/`CASHIER_*` no `.env.example`; `docs/poda.md` seção de cobrança atualizada; `composer remove laravel/cashier`.

- [ ] Fazer a poda em passos verificáveis (remover consumidores antes do pacote). Rodar a suíte após cada corte. Confirmar que **nada** importa `Laravel\Cashier` (`grep -rn "Laravel\\\\Cashier" app modules tests` vazio) antes do `composer remove`. Commit(s) `chore(billing): remove Cashier/Stripe, assinatura agora é Pix`.

> Risco alto e irreversível-ish. Fazer só depois das Tasks 1-8 verdes. O `grep` por `Laravel\Cashier` é o gate: zero ocorrências antes de tirar o pacote.

---

### Task 10: Verde total + docs

- [ ] `composer check` partido (host: pint/rector/peck/type-coverage; Sail: phpstan + suíte). Mutation: acrescentar `App\Support\Billing\Subscriptions`, `App\Actions\Billing\ActivatePlan`, `App\Actions\Billing\PurchasePlan` à lista `test:mutate`. Atualizar `docs/poda.md` e o spec (Fase D-a concluída; Cashier removido). Commit `chore(billing): fecha composer check e docs da assinatura Pix`.

---

## Self-Review

**Cobertura (D4/D6 do spec, parte "assinatura + remoção do Cashier"):**
- Assinatura como ciclo pré-pago renovável via Pix → Tasks 1,5,6,7. ✓
- Máquina de estados simplificada (sem dunning, sem read-only) → Tasks 2,3. ✓
- `TeamBilling` preservado como seam → Task 3. ✓
- Remoção do Cashier/Stripe → Task 9. ✓
- Reusa `PaymentIntent`/webhook da Fase C (purpose=plan) → Tasks 1,5,6. ✓
- Compra/renovação self-service via Pix → Task 7. ✓

**Fora de escopo (fica para D-b/D-c):** `plan_consultas` + franquia/quota + gating de consulta por plano (Fase D-b); overrides por usuário (Fase D-c). Esta fase entrega a assinatura e a remoção do Cashier; a franquia de consultas vem depois, lendo `TeamBilling::plan()`.

**Riscos:** (1) A poda do Cashier é irreversível-ish — só depois de 1-8 verdes, com o grep de `Laravel\Cashier` zerado como gate. (2) A migration que dropa `subscriptions`/colunas Stripe precisa preservar `trial_ends_at`. (3) `SubscriptionStatus` muda de casos — todo consumidor (UI, Filament, testes) atualiza junto; entre a Task 2 e a 3/8 a suíte fica vermelha de propósito (rodar só os testes da task). (4) `DemoSeeder` cria assinatura Cashier — migra para `PlanSubscription` na Task 9 ou o seeder quebra.

**Consistência de tipos:** `TeamBilling` mantém as assinaturas públicas exatas (o app inteiro depende delas). `Subscriptions::active/statusFor`, `ActivatePlan::handle(Team,Plan): PlanSubscription`, `PurchasePlan::handle(Team,Plan,PayerData): PaymentIntent`, `PaymentPurpose::Plan`, `Plan::periodDays/purchasable/isFree` — consistentes entre definição e consumo. O webhook usa `intent->purpose` e `intent->plan`.
