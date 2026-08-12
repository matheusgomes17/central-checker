# Fase D-c — Overrides por usuário (preço custom, liberar, bloquear) — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gestão particular por usuário: o admin pode bloquear um check para um usuário, liberá-lo de graça (comp) mesmo fora do plano, ou dar um preço em crédito específico — lido **por cima** da franquia/preço que já existem.

**Architecture:** Um model `UserCheckOverride` (escopado por time: `team_id`+`user_id`+`check_id`) com três alavancas — `blocked`, `granted`, `price_cents`. O `ExecutarCheck` resolve o override **antes** da franquia/crédito: `blocked` → recusa; `granted` → grátis (comp), ganha de tudo; senão o preço efetivo passa a ser `override.price_cents ?? check.price_cents` e a ordem da D-b roda igual. Override só se aplica quando há um usuário na execução.

**Nomes atuais:** módulo `Modules\CheckerApi`, models `Check`/`CheckExecution`, action `App\Actions\CheckerApi\ExecutarCheck`. Resolver de franquia `App\Support\Billing\Franquia` (Fase D-b).

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; docblocks inglês, prosa/comentários português.
- **Branch `main`** (sem novas branches). **Testes via Sail**; static split host/Sail; Pint no host. Nunca `sail up`, nunca `composer test -- <path>`.
- Dinheiro em centavos. Model de domínio escopado por `BelongsToTenant` (o override é do time/usuário).
- Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## Semântica das três alavancas (precedência)
1. `blocked = true` → o usuário não pode rodar este check → `CheckBlockedForUserException` (antes de tocar em franquia/crédito/provider). Bloqueio ganha de tudo.
2. `granted = true` → o usuário roda **grátis** (comp), independente de plano, franquia ou preço. Ganha da franquia e do crédito. (Se ambos `blocked` e `granted` estiverem setados, `blocked` vence.)
3. `price_cents` (nullable) → **preço em crédito específico** deste usuário; substitui o `check->price_cents` no caminho de crédito. Não muda a ordem — só o valor cobrado quando cai no crédito.

Sem override (ou sem usuário na execução), o comportamento da D-b fica idêntico.

## File Structure
```
app/Models/UserCheckOverride.php               # team+user+check -> blocked/granted/price_cents (scoped)
database/migrations/*_create_user_check_overrides_table.php
database/factories/UserCheckOverrideFactory.php
modules/CheckerApi/Exceptions/CheckBlockedForUserException.php
app/Actions/CheckerApi/ExecutarCheck.php        # resolve override antes da franquia/crédito
tests/Feature/CheckerApi/OverrideCheckTest.php
composer.json / spec                            # mutation + docs
```

---

### Task 1: Model UserCheckOverride
**Files:** `app/Models/UserCheckOverride.php`; `database/migrations/*_create_user_check_overrides_table.php`; `database/factories/UserCheckOverrideFactory.php`; test `tests/Feature/Billing/UserCheckOverrideTest.php`.

**Interfaces:**
- Migration `user_check_overrides`: `id`; `team_id` (FK→teams cascade, index); `user_id` (FK→users cascade); `check_id` (FK→`checks` cascade); `blocked` (boolean default false); `granted` (boolean default false); `price_cents` (unsignedInteger nullable); timestamps; `unique(['team_id','user_id','check_id'])`; index `check_id`.
- `UserCheckOverride` — `App\Models`, uses `BelongsToTenant` + `HasFactory` (escopado por time). `#[Fillable(['user_id','check_id','blocked','granted','price_cents'])]` (NÃO `team_id` — a trait preenche). Casts `blocked`/`granted` => 'boolean'. Relations `user()` (belongsTo User) e `check()` (belongsTo `Modules\CheckerApi\Models\Check`). `@property` docblock completo.
- Finder estático (padrão `Wallet::forTeam`): `public static function for(Team $team, User $user, Check $check): ?self` → `self::withoutTenancy(fn () => self::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->where('check_id', $check->getKey())->first())`. (withoutTenancy + team_id explícito para funcionar de qualquer contexto, como o ExecutarCheck que já tem tenant; espelha `Wallet::forTeam`.)

- [ ] Migration + model + factory (factory: team_id => Team::factory(), user_id => User::factory(), check_id => Check::factory(), blocked false, granted false, price_cents null; states `blocked()`, `granted()`, `priced(int $cents)`). Teste: cria override e lê as alavancas; `for()` acha o override certo e devolve null quando não há; isolamento — override de outro usuário não é retornado. Arch verde (model escopado usa a trait). Ver falhar. Commit `feat(billing): UserCheckOverride por usuário e check`.

---

### Task 2: CheckBlockedForUserException + gating em ExecutarCheck
**Files:** `modules/CheckerApi/Exceptions/CheckBlockedForUserException.php`; modify `app/Actions/CheckerApi/ExecutarCheck.php`; test `tests/Feature/CheckerApi/OverrideCheckTest.php`.

**Interfaces:**
- `CheckBlockedForUserException` (`Modules\CheckerApi\Exceptions`, RuntimeException): `::for(Check $check): self`.
- `ExecutarCheck` — depois de resolver `$team`, e recebendo o `?User $user`:
  ```php
  $override = $user instanceof User ? UserCheckOverride::for($team, $user, $check) : null;

  if ($override instanceof UserCheckOverride && $override->blocked) {
      throw CheckBlockedForUserException::for($check);
  }

  $hold = null;
  if ($override instanceof UserCheckOverride && $override->granted) {
      // Comp: grátis, ganha de plano/franquia/preço.
  } else {
      $price = $override?->price_cents ?? $check->price_cents;
      $franquia = $this->franquia->for($team, $check);
      if ($franquia instanceof FranquiaStatus && $franquia->hasRemaining()) {
          // grátis sob o plano
      } elseif ($price > 0) {
          $hold = $this->reserveCredit->handle($team, $price);
      } elseif ($franquia instanceof FranquiaStatus) {
          throw FranquiaExhaustedException::for($check);
      }
      // else: não coberto e preço 0 → grátis
  }
  // try/execute/record/settle/release igual (o confirm cobra o valor do hold, que é o preço efetivo)
  ```
  Import `App\Models\UserCheckOverride`, `App\Models\User` (já importado), `Modules\CheckerApi\Exceptions\CheckBlockedForUserException`. Manter o único `handle` público. A resolução do override roda no contexto do tenant que o action já tem.

- [ ] Casos novos (ver falhar): (1) `blocked` → `CheckBlockedForUserException`, nenhuma execução, nenhum hold; (2) `granted` → grátis mesmo para um check com preço que o plano não cobre (saldo inalterado, execução gravada); (3) `price_cents` custom → cai no crédito e cobra o valor CUSTOM, não o `check->price_cents` (fundear a carteira, conferir o débito exato); (4) sem override → comportamento D-b idêntico (franquia/crédito); (5) override de OUTRO usuário não afeta este (isolamento); (6) `$user` null → nenhum override aplicado. Reaproveitar helpers de FakeProvider/CreditWallet/PlanSubscription/PlanCheck dos testes de CheckerApi/Billing. Commit `feat(checker-api): overrides por usuário antes da franquia/crédito`.

---

### Task 3: Verde total + mutation + docs
- [ ] `composer.json` `test:mutate` `--class`: acrescentar `App\Models\UserCheckOverride`? (é model, não action — o mutation do projeto foca em regras; incluir `App\Actions\CheckerApi\ExecutarCheck` já cobre a gating — confirmar que está lá). `composer check` partido (host: pint/rector/peck/type-coverage; Sail: phpstan + suíte). Atualizar o spec: D5 (gestão por usuário) e a "Ordem de construção" marcando **D-c concluída** (D4 fechado); E/F seguem. Commit `chore(billing): fecha composer check e docs dos overrides`.

---

## Self-Review
**Cobertura (D5 do spec):** preço custom, liberar (grant), bloquear por usuário → Tasks 1-2. ✓ Precedência clara (block > grant > preço custom), lida por cima da franquia/preço da D-b sem alterá-los.
**Fora de escopo:** a TELA do admin para gerir overrides é a **Fase E** (Filament). D-c entrega o model + a resolução; o operador gere via tinker/seed até a E.
**Aresta:** `granted` é comp total (grátis) — não consome franquia nem crédito. Se no futuro "liberar" precisar significar "deixar pagar por crédito fora do plano", é outra alavanca; hoje um check com preço já é acessível por crédito sem plano, então `granted` cobre o caso que falta (dar de graça).
**Consistência de tipos:** `UserCheckOverride::for(Team,User,Check): ?self`, alavancas `blocked:bool`/`granted:bool`/`price_cents:?int`, `CheckBlockedForUserException::for(Check)`. `ExecutarCheck` mantém `handle(Check, CheckInput, ?User): CheckExecution`. O preço efetivo `override?->price_cents ?? check->price_cents` alimenta o `reserveCredit`, e o `confirm` cobra o valor do hold.
