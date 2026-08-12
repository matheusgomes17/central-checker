# Fase E-b — Admin: gestão por usuário (overrides, carteira/razão, execuções) — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao operador, no painel `/admin`, o resto do D7 que fica sobre o cliente: **Overrides por usuário** (bloquear/liberar/preço custom), **Carteira/Razão** (ler o saldo e o razão append-only de um time + creditar manualmente como `adjustment`) e **Execuções** (auditar as consultas rodadas, com PII sempre mascarada).

**Architecture:** Quatro Filament Resources auto-descobertas, no padrão de E-a. Diferença central desta fase: os quatro models são **tenant-scoped fail-closed** (`UserCheckOverride`, `Wallet`, `WalletTransaction`, `CheckExecution`), mas o painel do operador é **global e sem tenant ambiente** — então **toda** resource desta fase sobrescreve `getEloquentQuery()` para dropar o `TenantScope` (`->withoutGlobalScope(TenantScope::class)`, o mesmo padrão que `UserResource` usa para o `SoftDeletingScope`) e mostra a coluna `team.name` para o operador saber de quem é a linha. `UserCheckOverride` é editável (CRUD); as outras três são **leitura** (a carteira ganha uma **ação** de crédito, mas nunca edição de linha do razão — correção é outra linha, nunca um `update`). O crédito manual chama `Modules\Carteira\Actions\CreditWallet::handle(..., WalletTransactionType::Adjustment, ...)`, que já roda `forTenant($team)` e trava a linha da carteira.

**Nomes atuais:**
- `App\Models\UserCheckOverride` (scoped; `team_id`,`user_id`,`check_id`,`blocked`,`granted`,`price_cents`; `Fillable` **sem** `team_id`; unique `(team_id,user_id,check_id)`; `for(Team,User,Check)`).
- `Modules\Carteira\Models\Wallet` (scoped; `team_id`,`balance_cents`; `available(): int`; `forTeam(Team)`; `transactions()`).
- `Modules\Carteira\Models\WalletTransaction` (scoped; `team_id`,`wallet_id`,`type` (`WalletTransactionType`),`amount_cents`,`balance_after_cents`,`description`,`reference_type/id`; **imutável**).
- `Modules\Carteira\Enums\WalletTransactionType` (`Recharge`/`Charge`/`Refund`/`Bonus`/`Adjustment`; `isCredit()`).
- `Modules\Carteira\Actions\CreditWallet::handle(Team $team, int $amountCents, WalletTransactionType $type, string $description, ?Model $reference = null): WalletTransaction` (lança `InvalidArgumentException` se valor ≤ 0 ou tipo não-credita).
- `Modules\CheckerApi\Models\CheckExecution` (scoped; `team_id`,`check_id`,`user_id?`,`outcome` (`ProviderOutcome`),`consumed_franquia`,`duration_ms`,`input_masked` (array já mascarado),`result` (`encrypted:array` — **PII, nunca exibir**),`failure_reason?`).
- `App\Models\Scopes\TenantScope`. Painel gated por `User::canAccessPanel()` → `isSuperAdmin()`. `User`/`Team` **não** são scoped.

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; **docblocks inglês**, prosa/comentários português. `#[Override]` como os vizinhos.
- **Branch `main`** (sem novas branches). **Testes via Sail** (`./vendor/bin/sail artisan test --compact <path>`; suíte `--exclude-testsuite=Browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint no host. Use `resolve()` (Rector reescreve `app()`).
- Dinheiro em centavos no banco; formulário/ação em reais, `round` antes do cast (`(int) round(((float) $v) * 100)`).
- **Todo resource desta fase dropa o `TenantScope` no `getEloquentQuery()`** — sem isso o painel mostra tabela vazia (fail-closed). Mostre `team.name`.
- **PII:** a resource de Execuções **nunca** expõe `result`. Só `input_masked` (que já vem mascarado). Nada de coluna, infolist ou download do resultado cru.
- Filament v5 — antes de codar, `search-docs` (`['resource getEloquentQuery', 'action form modal', 'table money column', 'read only resource']`, `packages:['filament/filament']`). Espelhe E-a (`app/Filament/Resources/Checks`, `.../PlanChecks`) e a read-only `SubscriptionResource`.
- Resources são auto-descobertas; não registre à mão. Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure
```
app/Filament/Resources/UserCheckOverrides/{UserCheckOverrideResource.php,Schemas/UserCheckOverrideForm.php,Tables/UserCheckOverridesTable.php,Pages/{List,Create,Edit}UserCheckOverride*.php}
app/Filament/Resources/Wallets/{WalletResource.php,Tables/WalletsTable.php,Pages/ListWallets.php}          # leitura + ação Creditar
app/Filament/Resources/WalletTransactions/{WalletTransactionResource.php,Tables/WalletTransactionsTable.php,Pages/ListWalletTransactions.php}  # Razão, só leitura
app/Filament/Resources/CheckExecutions/{CheckExecutionResource.php,Tables/CheckExecutionsTable.php,Pages/ListCheckExecutions.php}              # Execuções, só leitura, PII mascarada
tests/Feature/Admin/{UserCheckOverrideResourceTest,WalletResourceTest,WalletTransactionResourceTest,CheckExecutionResourceTest}.php
docs/.../spec                                                                                              # D7 mais completo
```

## Decisão — grupos de navegação
- `Faturamento`: **Carteira** (Wallet) e **Razão** (WalletTransaction).
- `Operação`: **Overrides** (UserCheckOverride) e **Execuções** (CheckExecution).
(E-a já usa `Catálogo`. Não mexer nos resources existentes sem grupo.)

## Decisão — `team_id` na criação de override
`UserCheckOverride` tem `team_id` **fora** do `Fillable` (a trait o preenche pelo tenant). No painel não há tenant ambiente, então o `creating` hook lançaria `TenantIsMissingException`. A `CreateUserCheckOverride` page resolve isso sobrescrevendo `handleRecordCreation`:
```php
protected function handleRecordCreation(array $data): Model
{
    // Sem tenant ambiente no painel: setamos team_id explicitamente e
    // suspendemos a tenancy para o hook `creating` não exigir um.
    return UserCheckOverride::withoutTenancy(function () use ($data): UserCheckOverride {
        $override = new UserCheckOverride;
        $override->forceFill($data)->save();

        return $override;
    });
}
```
`forceFill` porque `team_id` não é fillable; o operador é confiável e o form só oferece os campos certos. Confirme a assinatura de `handleRecordCreation` no v5 via search-docs.

---

### Task 1: Resource Overrides (UserCheckOverride) — bloquear/liberar/preço custom

**Files:** create `app/Filament/Resources/UserCheckOverrides/{UserCheckOverrideResource.php,Schemas/UserCheckOverrideForm.php,Tables/UserCheckOverridesTable.php,Pages/{ListUserCheckOverrides,CreateUserCheckOverride,EditUserCheckOverride}.php}`; test `tests/Feature/Admin/UserCheckOverrideResourceTest.php`.

**Interfaces:**
- `UserCheckOverrideResource` — model `App\Models\UserCheckOverride`, grupo `'Operação'`, sort (ex. 12), ícone `Heroicon` (ex. `OutlinedUserMinus`), `modelLabel = 'override'` / `pluralModelLabel = 'overrides'`. **Editável.** `getPages()` index/create/edit.
  - `getEloquentQuery(): Builder` → `return parent::getEloquentQuery()->withoutGlobalScope(\App\Models\Scopes\TenantScope::class);` (import `Illuminate\Database\Eloquent\Builder`, `#[Override]`). **Sem isso a lista vem vazia.**
- `UserCheckOverrideForm::configure(Schema $schema): Schema`:
  - `Select team_id` — `->relationship('team','name')->required()->searchable()->preload()` (label "Time"). (`Team` não é scoped, resolve normal.)
  - `Select user_id` — `->relationship('user','name')->required()->searchable()` (label "Usuário").
  - `Select check_id` — `->relationship('check','name')->required()->searchable()` (label "Consulta").
  - `Toggle blocked` — label "Bloqueado" (helperText "Bloqueio vence tudo").
  - `Toggle granted` — label "Liberado (grátis)" (helperText "Liberação ignora plano e preço; se Bloqueado também estiver marcado, Bloqueado vence").
  - `TextInput price_cents` — reais↔centavos **nullable** (prefixo R$, numeric, minValue 0, step 0.01, label "Preço custom", helperText "Vazio = usa o preço do catálogo"): `->formatStateUsing(fn (?int $s): ?string => $s === null ? null : number_format($s/100, 2, '.', ''))`, `->dehydrateStateUsing(fn (?string $s): ?int => ($s === null || $s === '') ? null : (int) round(((float) $s) * 100))`.
  - **Unicidade do trio:** o par lógico é `(team_id,user_id,check_id)` unique. Surfe como erro de form (não 500), como no PlanCheck: no `Select check_id`, `->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('team_id', is_int_or_string($get('team_id')) ...)->where('user_id', ...))` — combine `team_id` e `user_id` no escopo. Confirme a API v5 via search-docs; se o combo de dois `where` no `modifyRuleUsing` ficar difícil, use `mutateFormDataBeforeCreate`/validação manual que barre o trio duplicado com erro amigável. **Os testes abaixo travam o comportamento.**
- `CreateUserCheckOverride` — sobrescreve `handleRecordCreation` conforme a Decisão acima.
- `EditUserCheckOverride` — `DeleteAction` no header. (Editar carrega via `getEloquentQuery`, já sem TenantScope.)
- `UserCheckOverridesTable::configure` — colunas: `team.name` (label Time, searchable), `user.name` (Usuário, searchable), `check.name` (Consulta, searchable), `blocked` (IconColumn boolean, label Bloqueado), `granted` (IconColumn boolean, label Liberado), `price_cents` (`->money('BRL', divideBy: 100)`, placeholder '—' quando null, label Preço). `->defaultSort('id','desc')`. Filtros: `SelectFilter::make('blocked')` (boolean) e `TernaryFilter`/`SelectFilter` para `granted` — o que for mais simples no v5.

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Admin/UserCheckOverrideResourceTest.php`, scaffold de superadmin de E-a: RefreshDatabase, seed RolesAndPermissionsSeeder, helper superAdmin() via setSystemRole, actingAs no setUp):
  1. `test_the_list_shows_overrides_across_teams` — cria 2 overrides em **times diferentes** (`UserCheckOverride::factory()` — confira os states; provavelmente precisa `team_id`/`user_id`/`check_id`), `Livewire::test(ListUserCheckOverrides::class)->assertOk()` depois `assertCanSeeTableRecords($both)`. **Prova o drop do TenantScope** (sem ele, 0 linhas).
  2. `test_an_operator_can_grant_a_check_for_a_user` — `Team::factory()`, `User::factory()`, `Check::factory()`; `Livewire::test(CreateUserCheckOverride::class)->fillForm(['team_id'=>$team->id,'user_id'=>$user->id,'check_id'=>$check->id,'granted'=>true])->call('create')->assertHasNoFormErrors()`; `assertDatabaseHas('user_check_overrides', ['team_id'=>$team->id,'user_id'=>$user->id,'check_id'=>$check->id,'granted'=>true])` — **prova que `team_id` foi gravado** apesar de fora do Fillable.
  3. `test_a_custom_price_is_stored_in_cents` — cria com `price_cents`=>'2.50' → `assertDatabaseHas(..., ['price_cents'=>250])`; e edição carrega '2.50' (`assertFormSet`).
  4. `test_the_same_trio_cannot_be_added_twice` — cria um override `(team,user,check)`; recriar o mesmo trio → `->assertHasFormErrors([...])`, `assertDatabaseCount('user_check_overrides', 1)`, sem 500. (Trocar só o `user` OU só o `check` deve passar — a unicidade é do trio, não de um campo.)
  5. `test_the_panel_stays_shut_for_an_ordinary_user` — `actingAs(User::factory()->create())`, `get('/admin/user-check-overrides')->assertForbidden()`.
  Veja falhar, implemente, verde. Commit `feat(admin): resource de overrides por usuário no painel`.

---

### Task 2: Carteira e Razão (Wallet leitura + crédito manual; WalletTransaction razão leitura)

**Files:** create `app/Filament/Resources/Wallets/{WalletResource.php,Tables/WalletsTable.php,Pages/ListWallets.php}`; `app/Filament/Resources/WalletTransactions/{WalletTransactionResource.php,Tables/WalletTransactionsTable.php,Pages/ListWalletTransactions.php}`; tests `tests/Feature/Admin/WalletResourceTest.php`, `tests/Feature/Admin/WalletTransactionResourceTest.php`.

**Interfaces — WalletResource (Carteira, leitura + ação Creditar):**
- Model `Modules\Carteira\Models\Wallet`, grupo `'Faturamento'`, sort (ex. 20), ícone (ex. `OutlinedWallet`), `modelLabel='carteira'`/`pluralModelLabel='carteiras'`. **Leitura:** `canCreate()/canEdit()/canDelete()` → `false` (como `SubscriptionResource`). `getPages()` só `index`.
- `getEloquentQuery()` dropa `TenantScope` (idem Task 1).
- `WalletsTable::configure` — colunas: `team.name` (Time, searchable), `balance_cents` (`->money('BRL', divideBy:100)`, label Saldo), coluna computada Disponível `->state(fn (Wallet $r): int => $r->available())` formatada em reais (`->money('BRL', divideBy:100)` — confirme se `state()` + `money()` compõem; senão use `formatStateUsing` com `number_format`). `->defaultSort('balance_cents','desc')`.
  - **Ação de linha `creditar`** (`Filament\Actions\Action::make('creditar')`, label "Creditar", ícone plus, `->requiresConfirmation()` ou modal de form): form da ação = `TextInput valor` (reais, required, numeric, min 0.01, prefixo R$) + `Textarea descricao` (required, label "Motivo"). `->action(function (Wallet $record, array $data): void { resolve(\Modules\Carteira\Actions\CreditWallet::class)->handle($record->team, (int) round(((float) $data['valor']) * 100), \Modules\Carteira\Enums\WalletTransactionType::Adjustment, $data['descricao']); })` seguido de `Notification::make()->success()...->send()`. (O `CreditWallet` já roda `forTenant` e trava a linha; o tipo `Adjustment` credita — vira linha `adjustment` no razão, exatamente o que o D7 pede.) Confirme a API de form-em-ação do v5 via search-docs (`->schema([...])` / `->form([...])`).

**Interfaces — WalletTransactionResource (Razão, só leitura):**
- Model `Modules\Carteira\Models\WalletTransaction`, grupo `'Faturamento'`, sort (ex. 21), ícone (ex. `OutlinedListBullet`), `modelLabel='lançamento'`/`pluralModelLabel='razão'`. **Leitura total:** create/edit/delete → false (o razão é append-only; correção é outra linha, nunca edição). `getPages()` só `index`. `getEloquentQuery()` dropa `TenantScope`.
- `WalletTransactionsTable::configure` — colunas: `team.name` (Time, searchable), `type` (badge; label Tipo; cor: crédito→success, `Charge`→danger — use `->color(fn (WalletTransactionType $s): string => $s->isCredit() ? 'success' : 'danger')`; rótulo PT via `formatStateUsing` mapeando os cases), `amount_cents` (`->money('BRL', divideBy:100)`, label Valor; opcional sinal), `balance_after_cents` (`->money('BRL', divideBy:100)`, label "Saldo após"), `description` (Descrição, wrap/limit), `created_at` (dateTime, label Quando, sortable). `->defaultSort('created_at','desc')`. Filtros: `SelectFilter::make('type')->options(...)`.

- [ ] **Escreva os testes primeiro e veja falhar:**
  `WalletResourceTest`:
  1. `test_the_list_shows_wallets_across_teams` — 2 `Wallet::factory()` em times diferentes; list `assertOk` + `assertCanSeeTableRecords` (prova drop do TenantScope).
  2. `test_wallets_are_read_only` — `assertFalse(WalletResource::canCreate())`, `assertFalse(WalletResource::canEdit($wallet))`, `assertFalse(WalletResource::canDelete($wallet))` (ou `assertArrayNotHasKey('edit', WalletResource::getPages())`).
  3. `test_an_operator_can_credit_a_wallet_and_it_becomes_an_adjustment` — `Wallet::factory()->create(['team_id'=>$team->id,'balance_cents'=>0])`; `Livewire::test(ListWallets::class)->callAction(TestAction::make('creditar')->table($wallet), data: ['valor'=>'10.00','descricao'=>'cortesia'])`; depois `$wallet->refresh()` → `balance_cents === 1000`; e `assertDatabaseHas('wallet_transactions', ['team_id'=>$team->id,'type'=>'adjustment','amount_cents'=>1000,'description'=>'cortesia'])`. **Prova o crédito manual → adjustment no razão.** (Confira a assinatura de `callAction(..., data: [...])` no v5.)
  4. `test_the_panel_stays_shut_for_an_ordinary_user` — `get('/admin/wallets')->assertForbidden()`.
  `WalletTransactionResourceTest`:
  5. `test_the_ledger_shows_lines_across_teams` — 2 `WalletTransaction::factory()` em times diferentes; list `assertOk` + `assertCanSeeTableRecords`.
  6. `test_the_ledger_is_append_only` — create/edit/delete false (asserts como no 2) — o razão não edita.
  7. `test_the_panel_stays_shut_for_an_ordinary_user` — `get('/admin/wallet-transactions')->assertForbidden()`.
  Veja falhar, implemente, verde. Commit `feat(admin): Carteira com crédito manual e Razão em leitura`.

---

### Task 3: Execuções (CheckExecution) — auditoria com PII mascarada

**Files:** create `app/Filament/Resources/CheckExecutions/{CheckExecutionResource.php,Tables/CheckExecutionsTable.php,Pages/ListCheckExecutions.php}`; test `tests/Feature/Admin/CheckExecutionResourceTest.php`.

**Interfaces:**
- Model `Modules\CheckerApi\Models\CheckExecution`, grupo `'Operação'`, sort (ex. 13), ícone (ex. `OutlinedClipboardDocumentList`), `modelLabel='execução'`/`pluralModelLabel='execuções'`. **Leitura total:** create/edit/delete → false. `getPages()` só `index`. `getEloquentQuery()` dropa `TenantScope`.
- `CheckExecutionsTable::configure` — colunas: `team.name` (Time, searchable), `user.name` (Usuário, placeholder '—' quando null, toggleable), `check.name` (Consulta, searchable — relação `check` existe? o model tem `check_id` mas **não** declara `check()`. Adicione `belongsTo(Check::class)` via `check()` no `CheckExecution` **ou** exiba `check.name` só se a relação existir; o caminho limpo é **adicionar a relação `check()`** ao model, docblock inglês — é leitura, não altera comportamento), `outcome` (badge; label Desfecho; cor: `Success`→success, `NoData`→gray, `Failed`→danger; rótulo PT via formatStateUsing), `consumed_franquia` (IconColumn boolean, label "Franquia", toggleable), `duration_ms` (numeric, sufixo ' ms', label Duração, toggleable), `input_masked` (label Entrada; **exibe o array já mascarado** — `->formatStateUsing(fn (array $s): string => collect($s)->map(fn ($v,$k) => "$k: $v")->implode(', '))`; NUNCA o `result`), `failure_reason` (label Falha, placeholder '—', toggleable), `created_at` (dateTime, label Quando, sortable). `->defaultSort('created_at','desc')`. Filtros: `SelectFilter::make('outcome')->options(...)`, `SelectFilter check_id` (relationship), e opcional por time.
- **PII (D9):** **não** existe coluna, infolist, nem ação que leia `result`. Não crie página View com o resultado. Só `input_masked` (já mascarado na origem).

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Admin/CheckExecutionResourceTest.php`):
  1. `test_the_list_shows_executions_across_teams` — 2 `CheckExecution::factory()` em times diferentes; `assertOk` + `assertCanSeeTableRecords` (prova drop do TenantScope).
  2. `test_executions_are_read_only` — create/edit/delete false; `getPages()` só index (sem 'edit'/'view').
  3. `test_the_raw_result_is_never_exposed_in_the_table` — cria uma execução com `result` contendo um marcador secreto (ex. `['segredo' => 'PII-NAO-VAZAR']`) e `input_masked` mascarado; renderiza a lista (`Livewire::test(ListCheckExecutions::class)`) e `assertDontSee('PII-NAO-VAZAR')` (o resultado cru não aparece); `assertSee` do trecho mascarado do `input_masked`. **Este é o teste-guarda da LGPD** — veja-o falhar contra uma versão que (de propósito, temporariamente) exponha `result`, para provar que ele acusa.
  4. `test_the_panel_stays_shut_for_an_ordinary_user` — `get('/admin/check-executions')->assertForbidden()`.
  Veja falhar, implemente, verde. Commit `feat(admin): auditoria de execuções com PII sempre mascarada`.

---

### Task 4: Verde total + docs

- [ ] Se adicionou `check()` ao `CheckExecution`, confirme que nada mais quebrou (é relação nova, leitura). `composer check` partido (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + `artisan test --exclude-testsuite=Browser`). Sem `@phpstan-ignore`/baseline. Atualize o spec (`docs/.../2026-08-10-plataforma-de-consultas-design.md`): no D7, marcar **Usuários/Overrides**, **Carteira/Razão** e **Execuções** entregues (Fase E-b); só **Providers** e **Relatórios** seguem em E-c. Na "Ordem de construção", anotar E-b concluída. Commit `chore(admin): fecha composer check e docs da gestão por usuário`.

---

## Self-Review
**Cobertura (D7):** "Usuários — overrides, crédito manual, preço custom, bloqueio" → Task 1 (overrides: blocked/granted/price) + Task 2 (crédito manual → `adjustment`). "Carteira/Razão — leitura append-only" → Task 2. "Execuções — auditoria sem PII crua" → Task 3. ✓ Sobram Providers + Relatórios → E-c.
**Fora de escopo (E-c):** saúde de provider, circuit breaker, healthcheck manual, relatórios/widgets. Não adiantar.
**Risco central — tenancy no painel:** todo resource dropa `TenantScope`; cada Task tem um teste com linhas de **times diferentes** que só passa com o drop. O crédito manual roda via `CreditWallet` (que reentra no `forTenant` do time-alvo), então o escopo volta a valer onde deve.
**Risco — PII (D9):** Task 3 tem um teste-guarda que assert `assertDontSee` do `result` cru e `assertSee` do `input_masked`. Nenhuma tela lê `result`.
**Aresta — `team_id` fora do Fillable:** `CreateUserCheckOverride::handleRecordCreation` seta via `forceFill` sob `withoutTenancy`; o teste 2 da Task 1 prova o `team_id` gravado.
**Aresta — `blocked` + `granted` juntos:** o form permite ambos; a precedência (bloqueio vence) já é do `ExecutarCheck` (D-c), não muda aqui. HelperText avisa.
**Consistência de tipos:** `CreditWallet::handle(Team,int,WalletTransactionType,string,?Model)`, `Wallet::available():int`, `WalletTransactionType::isCredit():bool`, `CheckExecution.input_masked:array`/`result` nunca lido. Selects por `->relationship()` usam `team()/user()/check()` (a de `check()` no `CheckExecution` é adicionada na Task 3). Todos os `getEloquentQuery` dropam `App\Models\Scopes\TenantScope`.
