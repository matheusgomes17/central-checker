# Fase F-2 — Produto: Carteira e Recarga Pix — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps usam checkbox (`- [ ]`). Implementação de telas usa a skill **frontend-design** (identidade "fogo" da F-1).

**Goal:** A tela **Carteira** do produto: ver o saldo, **recarregar via Pix** (gerar QR + copia-e-cola, com valor validado) e ler o **histórico do razão** (lançamentos append-only) + os **pagamentos de recarga**. Tudo na identidade fogo da F-1, reaproveitando o backend `Pagamentos` que já existe.

**Architecture:** Uma página Livewire 4 (volt) `checker/carteira` no grupo `{current_team}`. A recarga chama a action existente `Modules\Pagamentos\Actions\CreateRecharge` (valida min/max, abre `PaymentIntent` pendente com purpose=Recharge, pede o Pix ao gateway, guarda `copy_paste`/`qr_code`) — **não credita a carteira**; quem credita é o webhook `deposit.paid` (já implementado na Fase C/D). A tela mostra o QR (PNG base64) e o copia-e-cola como a página de billing já faz, mais o razão (`WalletTransaction`) e o histórico de recargas (`PaymentIntent` purpose=Recharge). Saldo e razão são dados do **time** (todos os membros veem); ids setados no servidor levam `#[Locked]`.

**Reaproveitar (existe — consumir, não reescrever):**
- `resolve(\Modules\Pagamentos\Actions\CreateRecharge::class)->handle(\App\Models\Team $team, int $amountCents, \Modules\Pagamentos\Support\PayerData $payer): \Modules\Pagamentos\Models\PaymentIntent`. Lança `\Modules\Pagamentos\Exceptions\RechargeAmountException` (valor fora de `billing.recharge.min_cents`..`max_cents`). O `PaymentIntent` volta com `copy_paste` (string) e `qr_code` (PNG base64|null).
- `\Modules\Pagamentos\Support\PayerData::for($user->name, $user->email)` (document/phone opcionais).
- Config: `billing.recharge.min_cents` (default 1000 = R$10), `max_cents` (100000 = R$1000), `expiration_seconds` (3600).
- `\Modules\Carteira\Models\Wallet::forTeam($team)->balance_cents`; `\Modules\Carteira\Models\WalletTransaction` (razão do time: `type` (`WalletTransactionType`), `amount_cents`, `balance_after_cents`, `description`, `created_at`); `\Modules\Carteira\Enums\WalletTransactionType` (`isCredit()`).
- `\Modules\Pagamentos\Models\PaymentIntent` (tenant-scoped; `amount_cents`, `status` (`PaymentStatus` — `Paid`/`Pending`/...), `purpose`, `created_at`, `copy_paste`, `qr_code`, `expires_at`).
- Permissão: `App\Enums\TeamPermission::ViewBilling` (ver) e `ManageBilling` (recarregar), como a página de billing gateia (`Auth::user()->hasTeamPermission($team, ...)`).
- Padrão de UI do Pix (espelhar): `resources/views/pages/settings/⚡billing.blade.php` (método `subscribe`, render do `<img src="data:image/png;base64,{{ $qr }}">` + bloco mono do copia-e-cola). Componentes fogo da F-1: `x-fire.{stat,glow}`.

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; **docblocks inglês**, prosa/comentários português.
- **Branch `main`**. **Testes via Sail** (`--exclude-testsuite=Browser`; Browser à parte com `composer test:browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint `--dirty` no host. `resolve()` não `app()`.
- **Flux é o gratuito** — conferir variante antes.
- Dinheiro em centavos; reais na tela (`number_format($cents/100, 2)` como billing, ou `Number::currency(.../100,'BRL','pt_BR')` — siga o que a tela vizinha usa por coerência).
- **Segurança (memória do projeto):** propriedade Livewire com **id setado no servidor** leva `#[Locked]`; valor de recarga é `wire:model` do usuário (não travar). Saldo/razão são do time (ok para todos os membros); nada per-user aqui. Ver [[livewire-prop-locked-e-escopo-por-usuario]].
- **Recarga não credita a carteira** — só o webhook credita. A tela nunca escreve saldo; só chama `CreateRecharge` e lê.
- Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure
```
resources/views/pages/checker/⚡carteira.blade.php   # tela Carteira (volt): saldo + recarga + razão
routes/web.php                                        # rota checker/carteira no grupo {current_team}
resources/views/pages/⚡dashboard.blade.php           # (Task 2/3) link "Carteira" no dashboard, se fizer sentido
tests/Feature/Product/CarteiraTest.php
docs/.../spec                                          # D8 parcial (F-2)
```

---

### Task 1: Tela Carteira — saldo + recarga Pix

**Files:** create `resources/views/pages/checker/⚡carteira.blade.php`; modify `routes/web.php` (rota `checker/carteira`, nome `checker.carteira`, no grupo `{current_team}`); test `tests/Feature/Product/CarteiraTest.php`.

FIRST: invoque a skill **frontend-design** e siga a identidade fogo da F-1.

**Interfaces:**
- Rota: `Route::livewire('checker/carteira', 'pages::checker.carteira')->name('checker.carteira')` no grupo `{current_team}` (auth+verified+EnsureTeamMembership). Se `RouteAuthorizationTest` exige entrada por rota autenticada, acrescente `checker.carteira` como as rotas `dashboard`/`checker.executar` já fazem.
- Componente volt (content-only, sem `<x-layouts::app>` wrapper — Livewire v4 aplica o layout): `mount()` com `abort_unless($this->team() !== null, 404)` e `abort_unless(Auth::user()->hasTeamPermission($this->team(), TeamPermission::ViewBilling), 403)`. Estado: `public string $valor = ''` (reais, `wire:model`); `#[Locked] public ?int $intentId = null` (id do PaymentIntent aberto, setado no servidor); e para exibir o Pix: expor via `#[Computed] intent()` que resolve `PaymentIntent` do `intentId` **do time** (tenant-scoped já garante o time; é dado do time, não per-user).
  - `#[Computed] saldo()` → `Wallet::forTeam($this->team())->balance_cents`.
  - `#[Computed] canManage()` → `hasTeamPermission(..., ManageBilling)`.
  - Ação `recarregar()`: `abort_unless($this->canManage, 403)`. Valida `valor` (numérico, > 0). Converte reais→centavos (`(int) round(((float) $this->valor) * 100)`). `try { $intent = resolve(CreateRecharge::class)->handle($this->team(), $cents, PayerData::for($user->name, $user->email)); $this->intentId = $intent->id; } catch (RechargeAmountException) { addError('valor', __('mensagem PT com o intervalo permitido')); return; }`. Toast de sucesso ("Escaneie o Pix para concluir a recarga").
  - O intervalo permitido (min/max) vem de `Config::integer('billing.recharge.min_cents'|'max_cents')` — mostre-o como dica no form (ex. "entre R$ 10,00 e R$ 1.000,00"), lendo do config, não hardcoded.
- View: dentro de `<x-fire.glow>`: um `x-fire.stat` grande com o **saldo** (tone fire/mono). Form de recarga (input de valor em reais com prefixo R$, dica do intervalo, botão fogo "Gerar Pix", `wire:loading` desabilita). Quando `intent()` existe: bloco do Pix — `<img src="data:image/png;base64,{{ $qr }}">` (se `qr_code`), o copia-e-cola num bloco mono com **botão copiar** (Alpine `x-on:click` no clipboard — free), valor e validade ("expira em …"). Copy PT, voz ativa. Estado sem saldo/sem recarga é convite à ação.
- (Opcional, se simples) `wire:poll` leve enquanto há um intent Pending para detectar `status === Paid` (o webhook credita) e mostrar "recarga confirmada" + saldo atualizado. Se adicionar, teste o flip Pending→Paid. Se não, o usuário recarrega a página e vê o saldo — aceitável no MVP; **documente a escolha**.

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Product/CarteiraTest.php`; scaffold de membro de time como os testes de F-1/billing; **não** dependa de rede — o gateway é resolvido do container, então registre/fake o `PaymentGateway` como os testes de `Pagamentos` já fazem — grep `modules/Pagamentos/tests` pelo fake/bind do gateway e reutilize):
  1. `test_a_carteira_carrega_e_mostra_o_saldo` — membro com saldo conhecido vê o valor em reais.
  2. `test_gerar_pix_abre_um_intent_de_recarga_e_mostra_qr_e_copia_e_cola` — `->set('valor','50,00')->call('recarregar')->assertHasNoErrors()`; assert `PaymentIntent` criado (purpose Recharge, amount 5000, status Pending), e a tela mostra o copia-e-cola (e o QR quando presente). **A carteira NÃO é creditada** (saldo inalterado — só o webhook credita).
  3. `test_valor_fora_do_intervalo_mostra_mensagem` — valor abaixo do mínimo (ex. R$1) → `assertHasErrors(['valor'])`, nenhum `PaymentIntent`, mensagem PT com o intervalo. (**Veja falhar** contra um `catch` ausente de `RechargeAmountException` — deve estourar sem o tratamento.)
  4. `test_valor_invalido_nao_gera_pix` — vazio/letras → erro de validação, nenhum intent.
  5. `test_recarregar_exige_permissao_de_gestao` — membro só com `ViewBilling` (sem `ManageBilling`) vê a tela mas `recarregar()` → 403.
  6. `test_a_pagina_exige_ser_membro_do_time` — não-membro → 403/404.
  Veja falhar, implemente, verde. Commit `feat(produto): tela de carteira com recarga via Pix`.

---

### Task 2: Histórico do razão + pagamentos de recarga

**Files:** modify `resources/views/pages/checker/⚡carteira.blade.php` (seções de histórico); modify `tests/Feature/Product/CarteiraTest.php` (+casos).

**Interfaces:**
- `#[Computed] razao()` → últimos N lançamentos do time: `WalletTransaction::query()->latest()->limit(20)->get()` (tenant-scoped → só o time; o produto roda no tenant). Cada linha: `type` (badge — crédito verde-neon/`ok`, débito laranja/`fire`; rótulo PT via `WalletTransactionType`), `amount_cents` (reais, com sinal), `balance_after_cents` (reais), `description`, `created_at`.
- `#[Computed] recargas()` → `PaymentIntent` do time com purpose=Recharge, `latest()` (siga o padrão da página de billing para a consulta; tenant-scoped basta). Cada linha: data, valor, status (Paid verde / Pending). Espelha a seção "Payments" do billing.
- View: duas seções abaixo da recarga — **Razão** (lançamentos) e **Recargas** (pagamentos). Estados vazios como convite ("nenhum lançamento ainda", "nenhuma recarga ainda"), não vácuo. Identidade fogo.

- [ ] **Escreva os testes primeiro e veja falhar** (+ em `CarteiraTest.php`):
  1. `test_o_razao_mostra_os_lancamentos_do_time` — cria `WalletTransaction` (ex. um `recharge` e um `charge`) do time; a tela mostra os dois com tipo, valor e saldo após; um lançamento de **outro time** não aparece (isolamento — o tenant garante).
  2. `test_o_historico_de_recargas_mostra_os_pagamentos` — cria `PaymentIntent` Recharge (Paid e Pending); a tela mostra ambos com status; um Plan-purpose **não** aparece na seção de recargas.
  3. `test_estados_vazios` — time sem lançamentos/recargas mostra os convites, não vácuo.
  Veja falhar, implemente, verde. Commit `feat(produto): razão e histórico de recargas na carteira`.

---

### Task 3: Verde total + docs

- [ ] `npm run build` (se o ambiente permitir; senão anote). `composer check` partido (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + `artisan test --exclude-testsuite=Browser`). Sem `@phpstan-ignore`/baseline. Atualize o spec (`docs/.../2026-08-10-...md`): no D8, marcar **Carteira / Recarga** entregue (Fase F-2); Planos self-service e Perfil/Histórico seguem em F-3/F-4. "Ordem de construção": F-2 concluída. Se fizer sentido, adicione um link "Carteira" na navegação/dashboard. Commit `chore(produto): fecha composer check e docs da carteira`.

---

## Self-Review
**Cobertura (D8):** "Carteira / Recarga — saldo, gerar Pix (QR + copia-e-cola), histórico do razão" → Task 1 (saldo + recarga Pix) + Task 2 (razão + recargas). ✓
**Fora de escopo (F-3/F-4):** Planos self-service (a página de billing já assina planos via Pix — F-3 traz isso para a identidade fogo do produto se o usuário quiser, ou apenas linka), Perfil, Histórico de consultas.
**Risco — recarga não credita:** a tela só chama `CreateRecharge` e lê; o crédito é do webhook. Teste 2 prova saldo inalterado ao gerar o Pix. Sem duplicar a lógica de crédito.
**Risco — gateway em teste:** não bater na rede; usar o fake/bind do `PaymentGateway` que os testes de `Pagamentos` já usam. Registrar no setUp.
**Risco — permissão:** ver = `ViewBilling`, recarregar = `ManageBilling`, como a página de billing. Teste 5 cobre.
**Risco — segurança:** `intentId` setado no servidor leva `#[Locked]`; `valor` é bind do usuário (não travar). Dado é do time, não per-user.
**Consistência de tipos:** `CreateRecharge::handle(Team,int,PayerData):PaymentIntent`; `PayerData::for(string,string):self`; `Wallet::forTeam(Team):Wallet`; `WalletTransactionType::isCredit():bool`; `PaymentStatus::Paid`. QR = PNG base64 no `<img data:...>`; copia-e-cola em bloco mono com botão copiar (Alpine, free).
