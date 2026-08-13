# Identificadores em inglês (URLs mantêm português) — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps usam checkbox (`- [ ]`).

**Goal:** Renomear todos os identificadores de código em português (namespaces de módulo, classes, exceptions, métodos, propriedades, nomes de rota, componentes Livewire) para inglês — **mantendo as URLs voltadas ao usuário em português** (`/checker/carteira`, `/checker/planos`, `/checker/executar`, `/checker/historico`, `/dashboard`). Prosa, comentários e mensagens de commit continuam em português (convenção do projeto).

**Architecture:** Renomeações mecânicas, uma por task, cada uma deixando a suíte inteira verde — um ref esquecido vira erro fatal (classe não encontrada / rota inexistente), então a suíte verde é quase-prova de completude. Módulos usam PSR-4 `"Modules\\": "modules/"`, então renomear um módulo = `git mv` do diretório + trocar o namespace em todos os arquivos do módulo + trocar todos os `use Modules\X\...` no resto do código + renomear o ServiceProvider + `composer dump-autoload` + ajustar Arch tests e a lista `test:mutate` do `composer.json`.

## Mapa de renomeação
| Português | Inglês |
|---|---|
| `Modules\Carteira` (namespace/dir) | `Modules\Wallet` |
| `CarteiraServiceProvider` | `WalletServiceProvider` |
| `Modules\Pagamentos` (namespace/dir) | `Modules\Payments` |
| `PagamentosServiceProvider` | `PaymentsServiceProvider` |
| `App\Support\Billing\Franquia` | `App\Support\Billing\Allowance` |
| `FranquiaStatus` | `AllowanceStatus` |
| `Modules\CheckerApi\Exceptions\FranquiaExhaustedException` | `AllowanceExhaustedException` |
| `App\Actions\CheckerApi\ExecutarCheck` | `ExecuteCheck` |
| páginas volt `checker.carteira/executar/planos/historico` | `checker.wallet/execute/plans/history` (arquivos `⚡wallet/execute/plans/history.blade.php`) |
| nomes de rota `checker.carteira/executar/planos/historico` | `checker.wallet/execute/plans/history` |
| métodos Livewire `assinar/recarregar/consultas/incluidas/razao/recargas/ver/executar` | `subscribe/recharge/checks/includedChecks/ledger/recharges/view/execute` |
| props/vars PT `saldo/valor/documento/execucoes/…` | `balance/amount/document/executions/…` |
| **URLs** `/checker/carteira`, `/checker/planos`, `/checker/executar`, `/checker/historico` | **mantêm português** |

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; **docblocks inglês**, prosa/comentários/commits em português. `resolve()` não `app()`.
- **Branch `main`**. **Testes via Sail** (`--exclude-testsuite=Browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint `--dirty` no host.
- Após renomear namespace de módulo: `./vendor/bin/sail composer dump-autoload` (dentro do container). A suíte inteira tem que passar depois de cada task.
- **URLs em português permanecem** — só o `->name(...)` e o componente `pages::checker.*` mudam; o path (`Route::livewire('checker/carteira', ...)`) fica.
- Não mexer no comportamento — é rename puro. Sem novo teste de lógica; ajustar os testes existentes que referenciam os nomes antigos (rota, método, classe, componente) para os novos, **sem enfraquecer**.
- Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

### Task 1: Módulo `Carteira` → `Wallet`
**Escopo:** `modules/Carteira/` (44 arquivos referenciam). `git mv modules/Carteira modules/Wallet`; trocar `namespace Modules\Carteira` → `Modules\Wallet` em todos os arquivos do módulo; `CarteiraServiceProvider` → `WalletServiceProvider` (classe + registro em `bootstrap/providers.php`/config); todos os `use Modules\Carteira\...` → `use Modules\Wallet\...` no app/modules/tests/database/config; `TenantManager::UNSCOPED_MODELS`/morph maps se referenciarem por string; Arch tests que citam `Carteira`; `composer.json` `test:mutate` (`Modules\Carteira\...` → `Modules\Wallet\...`). `./vendor/bin/sail composer dump-autoload`.
- Nota: as classes internas já são inglês (`Wallet`, `WalletTransaction`, `WalletHold`, `WalletTransactionType`, `CreditWallet`, `ReserveCredit`, `ConfirmHold`, `ReleaseHold`) — só o **namespace do módulo** e o **ServiceProvider** mudam.
- [ ] `git grep -n 'Modules\\Carteira\|Carteira'` deve voltar vazio (fora de docs históricas/PLANEJAMENTO). Suíte inteira verde. `composer check` partido. Commit `refactor(wallet): módulo Carteira renomeado para Wallet`.

### Task 2: Módulo `Pagamentos` → `Payments`
**Escopo:** `modules/Pagamentos/` (33 arquivos). Igual à Task 1: `git mv`, namespace, `PagamentosServiceProvider` → `PaymentsServiceProvider`, todos os `use Modules\Pagamentos\...`, Arch, `test:mutate`, dump-autoload. Classes internas já inglês (`PaymentIntent`, `PaymentGateway`, `VirtualPayGateway`, `CreateRecharge`, `PaymentPurpose`, `PaymentStatus`, `PayerData`, `GatewayCharge`).
- [ ] `git grep -n 'Modules\\Pagamentos\|Pagamentos'` vazio (fora de docs). Suíte verde. Commit `refactor(payments): módulo Pagamentos renomeado para Payments`.

### Task 3: `Franquia` → `Allowance`
**Escopo (9 arquivos):** `App\Support\Billing\Franquia` → `Allowance` (`app/Support/Billing/Franquia.php` → `Allowance.php`); `FranquiaStatus` → `AllowanceStatus`; `Modules\CheckerApi\Exceptions\FranquiaExhaustedException` → `AllowanceExhaustedException`; a propriedade/var `$franquia` (ex. no `ExecuteCheck`) → `$allowance`; método `for()` inalterado; `composer.json` `test:mutate` (`App\Support\Billing\Franquia` → `Allowance`); testes (`FranquiaTest` → `AllowanceTest`, `FranquiaCheckTest`, etc.) e refs. Manter prosa/docblocks explicando o conceito (a "franquia mensal" pode ser citada em comentário PT; o identificador é `Allowance`).
- [ ] `git grep -n 'Franquia'` vazio (fora de comentários/docs PT que expliquem o conceito). Suíte verde. Commit `refactor(billing): Franquia renomeada para Allowance`.

### Task 4: `ExecutarCheck` → `ExecuteCheck` + resto de actions/support PT
**Escopo:** `App\Actions\CheckerApi\ExecutarCheck` → `ExecuteCheck` (arquivo + classe + todos os refs + `test:mutate` + `composer.json` `test:mutate` já ajustado nas tasks anteriores onde citado); varrer `app/` e `modules/` por qualquer outro nome de classe/método PT remanescente (ex. em Actions, Support, Console Commands) e renomear. `git grep -niE 'executar|assinar|recarregar|consultar|franquia|carteira|pagamento'` em posições de identificador (class/function/method) para achar sobras.
- [ ] `git grep` de identificadores PT em `app/` e `modules/` (excluindo strings de UI, comentários, e URLs) vazio. Suíte verde. Commit `refactor(checker-api): ExecutarCheck renomeada para ExecuteCheck`.

### Task 5: Frontend do produto — páginas, rotas e métodos em inglês (URLs em PT)
**Escopo:** as 4 páginas volt do produto e seus testes.
- Renomear arquivos: `resources/views/pages/checker/⚡carteira.blade.php` → `⚡wallet.blade.php`; `⚡executar` → `⚡execute`; `⚡planos` → `⚡plans`; `⚡historico` → `⚡history`. (Manter o `⚡` e o diretório `checker/`.)
- `routes/web.php`: para cada uma, o **path fica em PT** e o componente + nome vão para inglês. Ex.: `Route::livewire('checker/carteira', 'pages::checker.wallet')->name('checker.wallet')`; idem `executar`→`pages::checker.execute`/`checker.execute` (path `checker/executar`), `planos`→`checker.plans` (path `checker/planos`), `historico`→`checker.history` (path `checker/historico`). O `dashboard` já é inglês.
- Métodos/props Livewire dentro dessas páginas → inglês conforme o mapa (`assinar`→`subscribe`, `recarregar`→`recharge`, `consultas`→`checks`, `incluidas`→`includedChecks`, `razao`→`ledger`, `recargas`→`recharges`, `ver`→`view`, `executar`→`execute`; props `saldo`→`balance`, `valor`→`amount`, `documento`→`document`, `execucoes`→`executions`, `intentId`/`selectedId`/`checkId`/`confirming` já inglês). Atualizar os `wire:click`/`wire:model` nas views de acordo.
- Atualizar TODAS as refs: `route('checker.carteira')`→`route('checker.wallet')` etc. em views (sidebar/dashboard/header/profile/welcome) e testes; `Livewire::test('pages::checker.carteira')`→`'pages::checker.wallet'` e `->call('assinar',...)`→`->call('subscribe',...)` etc. nos testes; `RouteAuthorizationTest` entries. Renomear os arquivos de teste se fizer sentido (`CarteiraTest`→`WalletTest`? opcional — o nome do arquivo de teste é identificador; renomear para `WalletTest`/`ExecuteCheckScreenTest`/`PlansTest`/`HistoryTest`).
- **Verificar que as URLs continuam em PT:** `route('checker.wallet')` deve produzir `/checker/carteira` (path inalterado). Um teste assere isso.
- [ ] Suíte inteira verde. `git grep` de identificador PT nas páginas/testes do produto vazio; as URLs seguem PT (teste). Commit `refactor(produto): páginas e rotas do produto com identificadores em inglês`.

### Task 6: Verde total + docs
- [ ] `composer check` completo (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + suíte; `lint:check` repo-wide verde). `npm run build`. Sem `@phpstan-ignore`/baseline. Atualizar docs/ADR se citarem os nomes antigos como identificador (não os históricos dos planos das fases). Confirmar o `composer.json` `test:mutate` todo atualizado. Commit `chore: fecha composer check dos identificadores em inglês`.

---

## Self-Review
**Cobertura:** o pedido ("identificadores em inglês, URLs em PT") → Tasks 1-5 renomeiam módulos, classes, exceptions, action, páginas, rotas e métodos; Task 6 fecha o verde. As URLs `/checker/carteira` etc. ficam. ✓
**Risco — namespace de módulo (PSR-4):** o maior risco; mitigado por `git grep` vazio + `dump-autoload` + suíte inteira verde após cada task. Um ref esquecido = fatal = vermelho.
**Risco — morph/strings:** se algum morph map ou string referencia `Modules\Carteira\Models\...`, o rename da classe muda o valor. Verificar morph maps; em dev não há dado de produção. O model `Wallet`/`PaymentIntent` (nome da classe) **não** muda — só o namespace do módulo; então o FQCN muda (`Modules\Carteira\Models\Wallet` → `Modules\Wallet\Models\Wallet`). Conferir morphs que usem FQCN.
**Risco — não enfraquecer testes:** os testes mudam só o nome referenciado (rota/método/classe/componente), nunca a asserção.
**Fora de escopo:** comportamento (rename puro), URLs (ficam PT), prosa/comentários (ficam PT), o re-skin visual (ortogonal).
**Nomes de julgamento:** `Franquia`→`Allowance` (franquia mensal = allowance). Se o usuário preferir outro, trocar antes da Task 3.
