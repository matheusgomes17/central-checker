# Fase E-a — Admin: catálogo (Consultas + Planos↔Check) — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao operador, no painel `/admin`, o CRUD do catálogo — a resource **Consultas** (`Check`: preço, provedor, status/manutenção, timeout) e a resource **Planos↔Check** (`PlanCheck`: qual plano inclui qual check, com franquia ou ilimitado) — as duas primeiras telas de **edição** do painel (as existentes são todas leitura).

**Architecture:** Duas Filament Resources auto-descobertas em `app/Filament/Resources`, no padrão dos vizinhos (`Resource` fino delegando `table()` a uma classe `Tables\*` e `form()` a um `Schemas\*Form`, com Pages List/Create/Edit). O `Check` mora em `Modules\CheckerApi\Models`; a resource é do app (o painel é do operador, transversal aos módulos). Preço é digitado em reais e persistido em centavos. `provider_key` é um `Select` alimentado por `ProviderRegistry::keys()` (não dá pra apontar para provedor inexistente). `PlanCheck.plan` é um `Select` das chaves de `Plan::all()`. Grupo de navegação **"Catálogo"** para as duas.

**Nomes atuais:** módulo `Modules\CheckerApi`, model `Modules\CheckerApi\Models\Check` (tabela `checks`, colunas `slug,name,description,category,provider_key,price_cents,status,timeout_seconds`), enum `Modules\CheckerApi\Enums\CheckStatus` (`Active`/`Maintenance`/`Inactive`). `App\Models\PlanCheck` (`plan,check_id,quota` — `quota` null = ilimitado, unique `(plan,check_id)`). `App\Support\Billing\Plan` (config, `Plan::all(): array<string,Plan>`, `->key`, `->name`). `Modules\CheckerApi\Support\ProviderRegistry::keys(): array<int,string>` (singleton no container). Painel gated por `User::canAccessPanel()` → `isSuperAdmin()`.

## Global Constraints
- PHP 8.4; `declare(strict_types=1)`; **docblocks em inglês**, prosa/comentários em português.
- **Branch `main`** (sem novas branches). **Testes via Sail** (`./vendor/bin/sail artisan test --compact <path>`; suíte `--exclude-testsuite=Browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail; Pint no host.
- Dinheiro em centavos no banco; o formulário mostra reais e converte na fronteira.
- Filament v5 — **antes de codar o form, rode `search-docs`** (`['resource form', 'select options', 'table action']`, `packages: ['filament/filament']`) para as APIs exatas de v5 (namespaces de `Schema`/componentes mudaram entre versões). Espelhe os vizinhos em `app/Filament/Resources/*`.
- Resources são **auto-descobertas** (`discoverResources`), não registre à mão no `AdminPanelProvider`.
- Todo teste novo é visto falhar antes de valer. Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## Decisão — reais↔centavos no formulário
`price_cents` é inteiro em centavos. No form, um `TextInput` numérico em reais:
- Carregar: `->formatStateUsing(fn (?int $state): ?string => $state === null ? null : number_format($state / 100, 2, '.', ''))`
- Salvar: `->dehydrateStateUsing(fn (?string $state): int => (int) round(((float) $state) * 100))`
`round` antes do cast fecha o erro de ponto flutuante (`4.90 * 100 = 489.9999…`); sem ele o preço encolhe um centavo. Prefixo `R$`, `->numeric()`, `->minValue(0)`, `->required()`, `->step('0.01')`.

## Decisão — o escopo desta sub-fase
E-a entrega **só o catálogo** (D7: "Consultas" e "Planos"). Ficam para depois: **Usuários/overrides + crédito manual + Carteira/Razão + Execuções** (E-b) e **Providers/saúde + Relatórios** (E-c). Não adiantar nada dessas aqui.

## File Structure
```
app/Filament/Resources/Checks/CheckResource.php
app/Filament/Resources/Checks/Schemas/CheckForm.php
app/Filament/Resources/Checks/Tables/ChecksTable.php
app/Filament/Resources/Checks/Pages/{ListChecks,CreateCheck,EditCheck}.php
app/Filament/Resources/PlanChecks/PlanCheckResource.php
app/Filament/Resources/PlanChecks/Schemas/PlanCheckForm.php
app/Filament/Resources/PlanChecks/Tables/PlanChecksTable.php
app/Filament/Resources/PlanChecks/Pages/{ListPlanChecks,CreatePlanCheck,EditPlanCheck}.php
tests/Feature/Admin/CheckResourceTest.php
tests/Feature/Admin/PlanCheckResourceTest.php
docs/.../spec                                  # marca D7 parcial
```

---

### Task 1: Resource Consultas (Check) — CRUD do catálogo

**Files:** create `app/Filament/Resources/Checks/CheckResource.php`, `.../Checks/Schemas/CheckForm.php`, `.../Checks/Tables/ChecksTable.php`, `.../Checks/Pages/{ListChecks,CreateCheck,EditCheck}.php`; test `tests/Feature/Admin/CheckResourceTest.php`.

**Interfaces:**
- `CheckResource extends Filament\Resources\Resource`, `protected static ?string $model = \Modules\CheckerApi\Models\Check::class;`. Ícone `Heroicon` (ex.: `OutlinedMagnifyingGlass`), `protected static ?string $navigationGroup = 'Catálogo';`, `$navigationSort` livre (ex. 10). `form()` → `CheckForm::configure($schema)`, `table()` → `ChecksTable::configure($table)`, `getPages()` com `index`/`create`/`edit`. **Editável** (não sobrescrever `canCreate`/`canEdit` para `false`). Use `#[Override]` como os vizinhos.
- `CheckForm::configure(Schema $schema): Schema` (confirme o tipo `Schema` no v5 via search-docs) com os campos:
  - `TextInput slug` — `->required()->unique(ignoreRecord: true)->rule('alpha_dash')` (slug estável; `ignoreRecord` para editar sem colidir consigo).
  - `TextInput name` — `->required()`.
  - `TextInput description` — `->nullable()`.
  - `TextInput category` — `->nullable()`.
  - `Select provider_key` — `->options(fn (): array => collect(app(\Modules\CheckerApi\Support\ProviderRegistry::class)->keys())->mapWithKeys(fn (string $k): array => [$k => $k])->all())->required()->native(false)`. (Só chaves registradas: apontar para provedor inexistente é bug ruidoso, não tela muda.)
  - `TextInput price_cents` — reais↔centavos conforme a Decisão acima (prefixo `R$`, numeric, minValue 0, step 0.01, required).
  - `Select status` — `->options(CheckStatus::class)->default(CheckStatus::Active)->required()->native(false)` (enum nativo do Filament v5; confirme o helper de enum via search-docs, senão mapeie os cases à mão).
  - `TextInput timeout_seconds` — `->numeric()->minValue(1)->maxValue(600)->default(30)->required()`.
- `ChecksTable::configure(Table $table): Table` — colunas: `name` (searchable), `slug` (searchable, toggleable), `category` (badge, toggleable), `provider_key` (badge), `price_cents` (`->money('BRL', divideBy: 100)` — confira a assinatura de `money()` no v5; o objetivo é mostrar R$ a partir de centavos), `status` (badge com cor: Active→success, Maintenance→warning, Inactive→gray), `updated_at` (dateTime, toggleable). `->defaultSort('name')`. Filtro `SelectFilter::make('status')->options(CheckStatus::class)`.
  - **Ação rápida de manutenção:** uma `Filament\Actions\Action::make('toggleMaintenance')` na linha que alterna `Active`⇄`Maintenance` (não toca em `Inactive`): `->label(fn (Check $r): string => $r->status === CheckStatus::Maintenance ? 'Reativar' : 'Manutenção')`, `->action(fn (Check $r) => $r->update(['status' => $r->status === CheckStatus::Maintenance ? CheckStatus::Active : CheckStatus::Maintenance]))`, `->requiresConfirmation()`. (É o "toggle de manutenção" do D7 sem abrir o form.)
- Pages: `ListChecks extends ListRecords` (com header action `CreateAction`), `CreateCheck extends CreateRecord`, `EditCheck extends EditRecord` (com `DeleteAction` no header) — `protected static string $resource = CheckResource::class;`. Confirme os nomes de `CreateAction`/`DeleteAction`/`headerActions()` no v5 via search-docs.

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Admin/CheckResourceTest.php`, espelhando `UserResourceTest`: `use RefreshDatabase`, `seed(RolesAndPermissionsSeeder::class)`, helper `superAdmin()` via `setSystemRole(SystemRole::SuperAdmin)`, `actingAs` no `setUp`). Registre um provider fake no container antes (ex.: reaproveitar o `FakeProvider` dos testes de CheckerApi via `app(ProviderRegistry::class)->register(...)` num setup, para `keys()` não vir vazio):
  1. `test_the_list_loads_and_shows_checks` — cria 3 `Check::factory()`, `Livewire::test(ListChecks::class)->assertOk()->assertCanSeeTableRecords($checks)`.
  2. `test_an_operator_can_create_a_check` — `Livewire::test(CreateCheck::class)->fillForm(['slug'=>'cpf-basico','name'=>'CPF Básico','provider_key'=><chave registrada>,'price_cents'=>'4.90','status'=>CheckStatus::Active->value,'timeout_seconds'=>30])->call('create')->assertHasNoFormErrors();` depois `assertDatabaseHas('checks', ['slug'=>'cpf-basico','price_cents'=>490])` — **prova a conversão reais→centavos** (490, não 4).
  3. `test_editing_shows_price_in_reais` — cria `Check::factory()->create(['price_cents'=>1234])`, `Livewire::test(EditCheck::class, ['record'=>$check->getKey()])->assertFormSet(['price_cents'=>'12.34'])` (centavos→reais no load).
  4. `test_provider_key_only_accepts_registered_providers` — `fillForm([... 'provider_key'=>'inexistente' ...])->call('create')->assertHasFormErrors(['provider_key'])` (o Select rejeita opção fora da lista).
  5. `test_the_maintenance_toggle_flips_status` — `Check::factory()->create(['status'=>CheckStatus::Active])`, `->callAction(TestAction::make('toggleMaintenance')->table($check))`, `$check->refresh()` → `CheckStatus::Maintenance`; de novo → `Active`.
  6. `test_the_panel_stays_shut_for_an_ordinary_user` — `actingAs(User::factory()->create())`, `$this->get('/admin/checks')->assertForbidden()`.
  Rode e veja falhar (resource não existe). Implemente Resource+Form+Table+Pages até passar. Commit `feat(admin): resource Consultas para o catálogo de checks`.

---

### Task 2: Resource Planos↔Check (PlanCheck) — inclusão com franquia

**Files:** create `app/Filament/Resources/PlanChecks/PlanCheckResource.php`, `.../Schemas/PlanCheckForm.php`, `.../Tables/PlanChecksTable.php`, `.../Pages/{ListPlanChecks,CreatePlanCheck,EditPlanCheck}.php`; test `tests/Feature/Admin/PlanCheckResourceTest.php`.

**Interfaces:**
- `PlanCheckResource` — `protected static ?string $model = \App\Models\PlanCheck::class;`, grupo `'Catálogo'`, ícone `Heroicon` (ex. `OutlinedRectangleStack`), `$navigationSort` depois do Check (ex. 11). Editável. `getPages()` index/create/edit. Rótulo: `protected static ?string $modelLabel = 'inclusão de plano';` (ou `getModelLabel()` — confira no v5) para a UI não dizer "Plan check".
- `PlanCheckForm::configure(Schema $schema): Schema`:
  - `Select plan` — `->options(fn (): array => collect(\App\Support\Billing\Plan::all())->mapWithKeys(fn (\App\Support\Billing\Plan $p): array => [$p->key => $p->name])->all())->required()->native(false)`.
  - `Select check_id` — `->relationship('check', 'name')->required()->native(false)->searchable()` (belongsTo `check()` já existe no model).
  - `TextInput quota` — `->numeric()->minValue(1)->nullable()->helperText('Vazio = ilimitado')`. (Null = ilimitado; a Decisão do D-b.)
  - Validação de unicidade do par: o par `(plan, check_id)` é unique no banco. Adote no form uma regra que respeite isso — o caminho v5 é `->unique()` com `ignoreRecord` num dos campos combinado com o outro, ou uma checagem no `mutateFormDataBeforeCreate`. Simples e robusto: no `CreatePlanCheck`, sobrescreva `handleRecordCreation`/`mutateFormDataBeforeCreate` para lançar erro de validação amigável se já existir `(plan,check_id)`; **os testes abaixo fixam o comportamento** (o banco já barra com 23000, mas queremos erro de form, não 500). Confirme a API exata via search-docs.
- `PlanChecksTable::configure(Table $table): Table` — colunas: `plan` (badge, `->formatStateUsing(fn (string $s): string => \App\Support\Billing\Plan::tryFind($s)?->name ?? $s)`, como `SubscriptionsTable::planName`), `check.name` (searchable), `quota` (`->formatStateUsing(fn (?int $s): string => $s === null ? 'Ilimitado' : (string) $s)->badge()->color(fn (?int $s): string => $s === null ? 'success' : 'gray')`), `updated_at` (toggleable). `->defaultSort('plan')`. Filtro `SelectFilter::make('plan')->options(<mesmas opções do form>)`.
- Pages análogas às da Task 1.

- [ ] **Escreva os testes primeiro e veja falhar** (`tests/Feature/Admin/PlanCheckResourceTest.php`, mesmo scaffold de superadmin):
  1. `test_the_list_loads_and_shows_inclusions` — 3 `PlanCheck::factory()`, list `assertOk` + `assertCanSeeTableRecords`.
  2. `test_an_operator_can_include_a_check_in_a_plan_with_a_quota` — `Check::factory()`, `Livewire::test(CreatePlanCheck::class)->fillForm(['plan'=>'pro','check_id'=>$check->id,'quota'=>100])->call('create')->assertHasNoFormErrors()`; `assertDatabaseHas('plan_checks',['plan'=>'pro','check_id'=>$check->id,'quota'=>100])`.
  3. `test_an_empty_quota_means_unlimited` — mesma coisa com `quota` vazio → `assertDatabaseHas('plan_checks',['plan'=>'pro','check_id'=>$check->id,'quota'=>null])`; e `PlanCheck::firstWhere(...)` `->isUnlimited()` true.
  4. `test_the_same_plan_and_check_cannot_be_added_twice` — cria uma `PlanCheck` `(pro,$check)`; tentar criar de novo → `->assertHasFormErrors(['check_id'])` (ou o campo que você escolher), **sem 500** e **sem segunda linha** (`assertDatabaseCount('plan_checks', 1)`).
  5. `test_the_panel_stays_shut_for_an_ordinary_user` — `get('/admin/plan-checks')->assertForbidden()`.
  Veja falhar, implemente, verde. Commit `feat(admin): resource Planos↔Check com franquia por inclusão`.

---

### Task 3: Verde total + docs

- [ ] `composer check` partido (host: `pint --dirty`, `refactor:check`, `spell:check`, `types:coverage`; Sail: `types:check` + `artisan test --exclude-testsuite=Browser`). Sem `@phpstan-ignore`/baseline. Atualize o spec (`docs/superpowers/specs/2026-08-10-plataforma-de-consultas-design.md`): no D7, marcar **Consultas** e **Planos** como entregues (Fase E-a); Providers/Relatórios/Usuários/Carteira/Execuções seguem em E-b/E-c. Na "Ordem de construção" (linha ~371), anotar E-a concluída. Commit `chore(admin): fecha composer check e docs do catálogo`.

---

## Self-Review
**Cobertura (D7 — parcial):** "Consultas — catálogo, preço, modo de cobrança, toggle de manutenção" → Task 1 (o "modo de cobrança" é `price_cents` + a franquia que vem do PlanCheck; o toggle de manutenção é a ação rápida). "Planos — inclusão de consultas + franquia/ilimitado" → Task 2. ✓
**Fora de escopo (E-b/E-c):** overrides por usuário, crédito manual, carteira/razão, execuções, saúde de provider, relatórios. Esta sub-fase é só o catálogo editável.
**Aresta — provider_key:** o Select só oferece chaves registradas em runtime; um `Check` já gravado apontando para um provider removido continua no banco (a resiliência da Fase A já trata a resolução ausente). O form novo não deixa criar um assim.
**Aresta — reais↔centavos:** `round()` antes do cast evita o erro de ponto flutuante; o teste 2 da Task 1 (`4.90 → 490`) trava isso. Sem o round, viraria 489.
**Consistência de tipos:** `Check` (`price_cents:int`, `status:CheckStatus`), `PlanCheck` (`plan:string`,`check_id:int`,`quota:?int`), `Plan::all():array<string,Plan>` com `->key`/`->name`, `ProviderRegistry::keys():array<int,string>`. Selects e formatadores usam essas assinaturas. As resources são do app (`App\Filament\Resources`), o `Check` é do módulo — o painel do operador é transversal, então não fere a regra de isolamento de módulo (que barra módulo↔módulo, não app→módulo).
