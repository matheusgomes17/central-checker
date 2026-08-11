# Ligar o plano ao produto — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o produto verificar de verdade os limites e recursos que os planos vendem, e fazer o catálogo parar de anunciar o que não existe.

**Architecture:** A verificação de limite passa do middleware — que nunca foi aplicado, e não podia ser, porque convite pela tela trafega pela rota do Livewire — para as actions por onde as três portas (modal, importação, API) já convergem. A contagem de recursos vira um registro extensível, com um teste que impede a config de nomear limite sem contador. O recurso `api` ganha middleware de rota, que aí funciona porque a API é HTTP puro.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4 (testes em classes no estilo PHPUnit, como o resto de `tests/Feature`), Pennant, Cashier, Livewire 4 + Flux, PostgreSQL.

**Especificação:** [`docs/superpowers/specs/2026-07-28-planos-ligados-ao-produto-design.md`](../specs/2026-07-28-planos-ligados-ao-produto-design.md)

## Global Constraints

- **Todo teste novo precisa ser visto falhar antes de valer.** Quebre o código que ele cobre, confirme que ele acusa, desfaça. Está no `CLAUDE.md` e não é opcional.
- **Docblocks `/** */` em inglês; comentários `//` e `/* */` em português.** O Peck roda no CI com dicionário em inglês e reprova prosa em português dentro de docblock. Nomes de método de teste em inglês.
- **Comentário explica o porquê, não o quê.** Comentário que reescreve a linha seguinte é ruído.
- **`composer check` precisa sair com exit 0** ao fim de cada tarefa: Pint, Rector, PHPStan `max` sem baseline, Peck, suíte e 100% de type coverage.
- **Catraca de mutation em `--min=97`.** `TeamBilling` e os middlewares estão na lista de classes medidas; a tarefa 7 confere.
- **Nada de abstração sem três usos reais.** O registro de contadores é a exceção justificada na especificação: ele existe para o produto plugar o segundo e o terceiro contador, e o teste de consistência é o que o torna obrigatório em vez de decorativo.
- **Cobertura mínima de 85%** (`composer test:coverage`).

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/Support/Billing/CountsPlanResource.php` (novo) | Contrato de um contador: dado um time, quantos daquele recurso ele consome |
| `app/Support/Billing/ResourceCounters.php` (novo) | Registro nome → contador; recusa recurso desconhecido |
| `app/Support/Billing/Counters/MemberCounter.php` (novo) | Conta membros mais convites pendentes não expirados |
| `app/Support/Billing/TeamBilling.php` | `countOf()` passa a consultar o registro |
| `app/Actions/Teams/InviteTeamMember.php` | Verifica o teto antes de criar o convite |
| `app/Actions/Teams/ImportInvitations.php` | Verifica o lote inteiro antes de despachar |
| `app/Http/Middleware/EnsurePlanFeature.php` (novo) | Recusa rota cujo recurso o plano do time não inclui |
| `app/Http/Middleware/EnsureWithinPlanLimits.php` | **Removido** |
| `app/Support/Api/ApiProblem.php` | Mapeia limite de plano e recurso de plano para 402 |
| `app/Exceptions/PlanFeatureException.php` (novo) | Recusa por recurso ausente no plano |
| `bootstrap/app.php` | Sai o alias `limits`, entra o alias `plan-feature` |
| `routes/api.php` | Aplica `plan-feature:api` |
| `config/billing.php` | Catálogo enxuto: sai `sso`, `audit-trail`, `projects`, `storage_mb` |
| `resources/views/pages/settings/⚡api-tokens.blade.php` | Diz por que não há formulário |
| `resources/views/pages/settings/⚡billing.blade.php` | Mostra excedente quando o time passou do teto |
| `database/seeders/DemoSeeder.php` | Ganha um time acima do teto, de propósito |
| `CLAUDE.md` | Documenta a ordem: contador primeiro, limite depois |

---

### Task 1: Registro de contadores, e a config parando de nomear limite sem contador

**Files:**
- Create: `app/Support/Billing/CountsPlanResource.php`
- Create: `app/Support/Billing/ResourceCounters.php`
- Create: `app/Support/Billing/Counters/MemberCounter.php`
- Modify: `app/Support/Billing/TeamBilling.php` (método `countOf()`, hoje no fim do arquivo)
- Modify: `app/Providers/AppServiceProvider.php` (método `configureBilling()`)
- Modify: `config/billing.php` (chaves `limits` dos três planos)
- Modify: `CLAUDE.md` (seção "Convenções deste projeto")
- Test: `tests/Feature/Billing/ResourceCountersTest.php`

**Interfaces:**
- Consumes: `App\Support\Billing\TeamBilling::for(Team $team): self`, `App\Support\Billing\Plan::all(): array<string, Plan>`, `Plan->limits` (`array<string, int|null>`).
- Produces:
  - `App\Support\Billing\CountsPlanResource::count(Team $team): int`
  - `App\Support\Billing\ResourceCounters::register(string $resource, CountsPlanResource $counter): void`
  - `App\Support\Billing\ResourceCounters::countFor(string $resource, Team $team): int` — lança `InvalidArgumentException` para recurso sem contador
  - `App\Support\Billing\ResourceCounters::registered(): array<int, string>`
  - `App\Support\Billing\Counters\MemberCounter` (sem construtor)

- [ ] **Step 1: Escrever o teste que falha**

Crie `tests/Feature/Billing/ResourceCountersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\Plan;
use App\Support\Billing\ResourceCounters;
use App\Support\Billing\TeamBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A plan that names a limit nobody can count is a plan that promises a ceiling
 * and enforces nothing. The consistency test is what turns that from a silent
 * zero into a failure.
 */
class ResourceCountersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_invitation_counts_against_the_ceiling(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $team->invitations()->create([
            'email' => 'convidada@example.test',
            'role' => TeamRole::Member,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        // Um membro e um convite pendente: o time já ocupa dois dos três
        // assentos do plano gratuito, mesmo com uma pessoa só dentro.
        $this->assertSame(2, TeamBilling::for($team)->usage('members')['used']);
    }

    public function test_an_expired_invitation_frees_the_seat(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $team->invitations()->create([
            'email' => 'expirada@example.test',
            'role' => TeamRole::Member,
            'invited_by' => $owner->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertSame(1, TeamBilling::for($team)->usage('members')['used']);
    }

    public function test_an_accepted_invitation_is_not_counted_twice(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $team->invitations()->create([
            'email' => 'aceita@example.test',
            'role' => TeamRole::Member,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        $this->assertSame(1, TeamBilling::for($team)->usage('members')['used']);
    }

    public function test_a_resource_with_no_counter_is_an_error_and_not_a_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        resolve(ResourceCounters::class)->countFor('projects', Team::factory()->create());
    }

    /**
     * The rule the config has to obey: name a limit only for something the
     * product can count. Adding `projects` to a plan without registering a
     * counter fails here instead of reporting zero use forever.
     */
    public function test_every_limit_any_plan_names_has_a_counter(): void
    {
        $registered = resolve(ResourceCounters::class)->registered();

        foreach (Plan::all() as $plan) {
            foreach (array_keys($plan->limits) as $resource) {
                $this->assertContains(
                    $resource,
                    $registered,
                    "O plano [{$plan->key}] limita [{$resource}] e não há contador registrado para esse recurso.",
                );
            }
        }
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact tests/Feature/Billing/ResourceCountersTest.php
```

Esperado: falha. Os três primeiros com `Failed asserting that 1 is identical to 2` ou similar (hoje `countOf` conta só membership); o quarto com "não lançou exceção"; o quinto acusando `projects` e `storage_mb` sem contador.

- [ ] **Step 3: Criar o contrato**

`app/Support/Billing/CountsPlanResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Team;

/**
 * Counts how much of one metered resource a team is using.
 *
 * The boilerplate ships one of these, for members. The product adds its own —
 * and the consistency test refuses a plan that names a limit before its
 * counter exists, which is the order that keeps a ceiling from being fiction.
 */
interface CountsPlanResource
{
    public function count(Team $team): int;
}
```

- [ ] **Step 4: Criar o registro**

`app/Support/Billing/ResourceCounters.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Team;
use InvalidArgumentException;

/**
 * Maps a metered resource to whoever knows how to count it.
 *
 * Answering zero for an unknown resource is what the previous implementation
 * did, and it is the worst of the options: the plan reports room that nobody
 * is measuring, and nothing ever says so.
 */
class ResourceCounters
{
    /**
     * @var array<string, CountsPlanResource>
     */
    private array $counters = [];

    public function register(string $resource, CountsPlanResource $counter): void
    {
        $this->counters[$resource] = $counter;
    }

    public function countFor(string $resource, Team $team): int
    {
        $counter = $this->counters[$resource] ?? null;

        if (! $counter instanceof CountsPlanResource) {
            throw new InvalidArgumentException(
                "Nenhum contador registrado para o recurso [{$resource}]. Registre um antes de limitá-lo em config/billing.php.",
            );
        }

        return $counter->count($team);
    }

    /**
     * @return array<int, string>
     */
    public function registered(): array
    {
        return array_keys($this->counters);
    }
}
```

- [ ] **Step 5: Criar o contador de membros**

`app/Support/Billing/Counters/MemberCounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Billing\Counters;

use App\Models\Team;
use App\Support\Billing\CountsPlanResource;

/**
 * Members plus the invitations still waiting to be accepted.
 *
 * Counting only memberships makes the ceiling trivially avoidable: a team two
 * seats from its limit sends fifty invitations and ends up fifty seats over it
 * when they are accepted. An expired invitation frees its seat again.
 */
class MemberCounter implements CountsPlanResource
{
    public function count(Team $team): int
    {
        $pending = $team->invitations()
            ->whereNull('accepted_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        return $team->memberships()->count() + $pending;
    }
}
```

- [ ] **Step 6: Ligar o registro e trocar o `countOf()`**

Em `app/Providers/AppServiceProvider.php`, dentro de `configureBilling()`, acrescente ao fim do método:

```php
        // O contador de membros é a implementação de referência. O produto
        // registra os dele aqui, e só então pode limitá-los na config — o teste
        // de consistência falha na ordem inversa, e é essa a intenção.
        $this->app->singleton(ResourceCounters::class, function (): ResourceCounters {
            $counters = new ResourceCounters();
            $counters->register('members', new MemberCounter());

            return $counters;
        });
```

Acrescente os `use` correspondentes no topo do arquivo:

```php
use App\Support\Billing\Counters\MemberCounter;
use App\Support\Billing\ResourceCounters;
```

Em `app/Support/Billing/TeamBilling.php`, substitua o método `countOf()` inteiro por:

```php
    /**
     * Current consumption of a metered resource.
     *
     * The registry answers, and it refuses a resource nobody registered. That
     * refusal is the point: a plan limiting something the product cannot count
     * is a ceiling that never applies.
     */
    private function countOf(string $resource): int
    {
        return resolve(ResourceCounters::class)->countFor($resource, $this->team);
    }
```

E acrescente o `use` no topo do arquivo:

```php
use App\Support\Billing\ResourceCounters;
```

- [ ] **Step 7: Enxugar o catálogo de limites**

Em `config/billing.php`, remova as chaves `projects` e `storage_mb` dos três planos, deixando apenas `members`. Substitua o bloco de comentário acima de `'plans' => [` acrescentando, ao fim dele:

```
    | Só entra aqui limite que tenha contador registrado em ResourceCounters.
    | O boilerplate não tem projeto nem armazenamento, então anunciá-los seria
    | vender teto que ninguém mede — veja o CLAUDE.md para acrescentar o seu.
```

Os limites ficam assim: `free` com `'members' => 3`, `pro` com `'members' => 20`, `business` com `'members' => null`.

- [ ] **Step 8: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Billing/ResourceCountersTest.php tests/Feature/Billing/TeamBillingTest.php
```

Esperado: PASS nos dois arquivos. Se `TeamBillingTest` acusar `a_plan_without_a_ceiling_reports_null_rather_than_zero` ou `usage_reports_the_limit_and_what_is_left`, ajuste os números esperados: eles contavam só membership e agora somam convite pendente — nenhum desses testes cria convite, então os números devem seguir iguais.

- [ ] **Step 9: Documentar a ordem no CLAUDE.md**

Na seção "Convenções deste projeto", logo após o bloco "Multi-tenancy é a premissa, não um recurso", acrescente:

```markdown
## Limite de plano: contador primeiro, limite depois

Nesta ordem, sempre. Registre o contador em `ResourceCounters` e só então
nomeie o limite em `config/billing.php`:

```php
$counters->register('projects', new ProjectCounter());
```

O teste `ResourceCountersTest::test_every_limit_any_plan_names_has_a_counter`
falha na ordem inversa, de propósito: limite sem contador é teto que nunca se
aplica, e `usage()` responderia "0 usados" para sempre.
```

- [ ] **Step 10: Red-check dos testes novos**

Confirme que cada teste falha pelo motivo certo:

```bash
# 1. Faça MemberCounter ignorar convites (troque o `+ $pending` por `+ 0`) e rode:
php artisan test --compact --filter=pending_invitation_counts tests/Feature/Billing/ResourceCountersTest.php   # deve FALHAR
# 2. Desfaça. Registre um contador falso para 'projects' e rode:
php artisan test --compact --filter=resource_with_no_counter tests/Feature/Billing/ResourceCountersTest.php    # deve FALHAR
# 3. Desfaça. Devolva 'projects' => 1 ao plano free na config e rode:
php artisan test --compact --filter=every_limit_any_plan_names tests/Feature/Billing/ResourceCountersTest.php  # deve FALHAR
# 4. Desfaça tudo e confirme verde.
php artisan test --compact tests/Feature/Billing/ResourceCountersTest.php
```

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add app/Support/Billing config/billing.php app/Providers/AppServiceProvider.php CLAUDE.md tests/Feature/Billing/ResourceCountersTest.php
git commit -m "feat(billing): contadores viram registro e a config para de anunciar teto que ninguém mede

O countOf() devolvia zero para todo recurso que não fosse membro, então
projects e storage_mb eram anunciados nos três planos e nunca limitavam nada —
usage() respondia '0 usados' para sempre. Os dois saem do catálogo e voltam
quando houver contador.

O contador de membros passou a somar convite pendente. Contar só membership
deixava o teto contornável sem nem tentar: um time a dois assentos do limite
manda cinquenta convites e termina cinquenta acima quando forem aceitos.

O teste de consistência é o que sustenta a regra: limite nomeado sem contador
registrado falha, em vez de virar teto silencioso."
```

---

### Task 2: O limite passa a valer no convite individual, e o middleware sai

**Files:**
- Modify: `app/Actions/Teams/InviteTeamMember.php`
- Modify: `app/Support/Api/ApiProblem.php` (método `from()`)
- Modify: `bootstrap/app.php` (bloco `$middleware->alias([...])`)
- Modify: `composer.json` (lista de classes do script `test:mutate`)
- Delete: `app/Http/Middleware/EnsureWithinPlanLimits.php`
- Test: `tests/Feature/Billing/PlanLimitTest.php`

**Interfaces:**
- Consumes: `ResourceCounters` e `TeamBilling::withinLimit(string $resource, int $adding = 1): bool` da Task 1; `App\Exceptions\PlanLimitException::reached(string $resource, Plan $plan, int $limit): self` (já existe).
- Produces: `InviteTeamMember::handle()` mantém a assinatura `(Team $team, string $email, TeamRole $role, User $invitedBy): TeamInvitation` e passa a lançar `PlanLimitException`.

- [ ] **Step 1: Escrever o teste que falha**

Crie `tests/Feature/Billing/PlanLimitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Teams\InviteTeamMember;
use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Exceptions\PlanLimitException;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ceiling the free plan advertises, finally applied.
 *
 * It is checked in the action and not in middleware because inviting from the
 * interface travels through Livewire's own route: route middleware never sees
 * it. The three doors — dialog, import and API — already meet in the action.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_action_refuses_the_invitation_that_would_cross_the_ceiling(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        $this->expectException(PlanLimitException::class);

        resolve(InviteTeamMember::class)->handle($team, 'nova@example.test', TeamRole::Member, $owner);
    }

    public function test_the_refused_invitation_is_not_created(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        try {
            resolve(InviteTeamMember::class)->handle($team, 'nova@example.test', TeamRole::Member, $owner);
        } catch (PlanLimitException) {
            // O que importa é o efeito colateral, não a exceção — ela já tem
            // teste próprio acima.
        }

        $this->assertSame(0, TeamInvitation::query()->where('email', 'nova@example.test')->count());
    }

    /**
     * The message carries the numbers. "Upgrade to continue" with no plan name
     * and no ceiling is the kind of wall people open support tickets about.
     */
    public function test_the_message_names_the_plan_and_the_ceiling(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        try {
            resolve(InviteTeamMember::class)->handle($team, 'nova@example.test', TeamRole::Member, $owner);
            $this->fail('A action deveria ter recusado o convite.');
        } catch (PlanLimitException $e) {
            $this->assertStringContainsString('Free', $e->getMessage());
            $this->assertStringContainsString('3', $e->getMessage());
        }
    }

    public function test_a_team_below_the_ceiling_still_invites(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();
        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $invitation = resolve(InviteTeamMember::class)->handle($team, 'cabe@example.test', TeamRole::Member, $owner);

        $this->assertSame('cabe@example.test', $invitation->email);
    }

    /**
     * Over the API the refusal must not read as our fault. PlanLimitException
     * extends RuntimeException, and without a branch of its own ApiProblem
     * answers 500 server_error for a ceiling working exactly as designed.
     */
    public function test_the_api_answers_payment_required_and_not_a_server_error(): void
    {
        [$team, $owner] = $this->teamAtItsCeiling();

        $token = $owner->createToken('teste', ApiAbility::Write->implies());
        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $team->id]);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/invitations', ['email' => 'nova@example.test', 'role' => TeamRole::Member->value])
            ->assertStatus(402)
            ->assertJsonPath('title', 'plan_limit_reached');
    }

    /**
     * @return array{Team, User}
     */
    private function teamAtItsCeiling(): array
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        // O plano gratuito comporta três assentos; dois membros a mais fecham
        // a conta sem precisar de convite pendente no meio.
        foreach (range(1, 2) as $ignored) {
            $team->members()->attach(User::factory()->create());
        }

        return [$team, $owner];
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact tests/Feature/Billing/PlanLimitTest.php
```

Esperado: os quatro primeiros falham porque nenhuma exceção é lançada; o último falha com status 201 ou 500 em vez de 402.

- [ ] **Step 3: Verificar o limite na action**

Em `app/Actions/Teams/InviteTeamMember.php`, substitua o corpo de `handle()` acrescentando a verificação antes da criação:

```php
    public function handle(Team $team, string $email, TeamRole $role, User $invitedBy): TeamInvitation
    {
        $billing = TeamBilling::for($team);

        // Aqui e não em middleware: o convite pela tela trafega pela rota do
        // Livewire, então middleware de rota nunca cobriria essa porta. As três
        // — modal, importação e API — passam por esta action.
        if (! $billing->withinLimit('members')) {
            throw PlanLimitException::reached('members', $billing->plan(), $billing->plan()->limit('members') ?? 0);
        }

        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role,
            'invited_by' => $invitedBy->getKey(),
            'expires_at' => now()->addDays(Config::integer('teams.invitation_expires_days')),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        return $invitation;
    }
```

Acrescente os `use` no topo:

```php
use App\Exceptions\PlanLimitException;
use App\Support\Billing\TeamBilling;
```

- [ ] **Step 4: Mapear a exceção no formato Problem Details**

Em `app/Support/Api/ApiProblem.php`, dentro do `match (true)` de `from()`, acrescente um braço **antes** do braço `HttpExceptionInterface`:

```php
            $e instanceof PlanLimitException => new self(
                Response::HTTP_PAYMENT_REQUIRED,
                'plan_limit_reached',
                $e->getMessage(),
            ),
```

Acrescente o `use` no topo:

```php
use App\Exceptions\PlanLimitException;
```

O 402 é deliberado e casa com a suspensão por inadimplência, que já responde 402: recusa com forma de dinheiro é 402, recusa com forma de permissão é 403.

- [ ] **Step 5: Remover o middleware que nunca foi aplicado**

```bash
git rm app/Http/Middleware/EnsureWithinPlanLimits.php
```

Em `bootstrap/app.php`, remova a linha `'limits' => EnsureWithinPlanLimits::class,` do bloco `$middleware->alias([...])` e o `use App\Http\Middleware\EnsureWithinPlanLimits;` do topo.

Em `composer.json`, no script `test:mutate`, remova `App\\Http\\Middleware\\EnsureWithinPlanLimits,` da lista de `--class=`. Deixar uma classe que não existe mais na lista faz o mutation medir menos do que anuncia, sem avisar.

- [ ] **Step 6: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Billing/PlanLimitTest.php tests/Feature/Teams tests/Feature/Api
```

Esperado: PASS. Testes existentes de convite que criem times com muitos membros podem acusar — o plano gratuito comporta três, e é isso que a mudança faz valer. Ajuste esses testes assinando um plano maior (veja `TeamBillingTest::subscribed()` para o padrão) e não afrouxando o limite.

- [ ] **Step 7: Red-check**

```bash
# Troque `! $billing->withinLimit('members')` por `false` e rode:
php artisan test --compact tests/Feature/Billing/PlanLimitTest.php   # deve FALHAR nos cinco
# Desfaça. Troque o braço novo do ApiProblem para HTTP_FORBIDDEN e rode:
php artisan test --compact --filter=payment_required tests/Feature/Billing/PlanLimitTest.php   # deve FALHAR
# Desfaça e confirme verde.
```

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add app/Actions/Teams/InviteTeamMember.php app/Support/Api/ApiProblem.php bootstrap/app.php tests/Feature/Billing/PlanLimitTest.php
git commit -m "feat(billing): o teto de membros passa a valer, na action e não em middleware

O limite não era verificado em lugar nenhum: withinLimit só era chamado pelo
EnsureWithinPlanLimits, e esse middleware não estava em rota alguma. Os dois
apareciam com 0% de cobertura, não por falta de teste mas porque nada os
executava. Um time no plano gratuito, teto de três, convidava cinquenta.

Não foi esquecimento terem deixado o alias sem rota: convite pela tela trafega
pela rota do Livewire, e middleware de rota nunca cobriria essa porta. O
docblock do middleware afirmava o contrário. Ele sai, com o alias.

E a API respondia 500 para o limite atingido: PlanLimitException estende
RuntimeException e caía no default do ApiProblem. Passa a responder 402, como a
suspensão por inadimplência — recusa com forma de dinheiro é 402."
```

---

### Task 3: A importação em lote recusa o lote inteiro

**Files:**
- Modify: `app/Actions/Teams/ImportInvitations.php` (método `handle()`, por volta da linha 33)
- Test: `tests/Feature/Queue/ImportInvitationsTest.php` (acrescentar casos)

**Interfaces:**
- Consumes: `TeamBilling::withinLimit(string $resource, int $adding): bool`, `PlanLimitException::reached()`.
- Produces: `ImportInvitations::handle()` mantém a assinatura atual e passa a lançar `PlanLimitException` antes de despachar qualquer chunk.

- [ ] **Step 1: Escrever o teste que falha**

Acrescente a `tests/Feature/Queue/ImportInvitationsTest.php`:

```php
    /**
     * Whole batch or nothing. Accepting up to the ceiling leaves the question
     * "which ones went in", and no screen can answer it.
     */
    public function test_a_batch_that_crosses_the_ceiling_is_refused_whole(): void
    {
        $emails = ['a@example.test', 'b@example.test', 'c@example.test', 'd@example.test'];

        try {
            resolve(ImportInvitations::class)->handle($this->team, $emails, TeamRole::Member, $this->owner);
            $this->fail('A importação deveria ter sido recusada.');
        } catch (PlanLimitException $e) {
            $this->assertStringContainsString('Free', $e->getMessage());
        }

        $this->assertSame(0, TeamInvitation::query()->whereIn('email', $emails)->count());
    }

    public function test_a_batch_that_fits_is_imported(): void
    {
        Bus::fake();

        resolve(ImportInvitations::class)->handle($this->team, ['cabe@example.test'], TeamRole::Member, $this->owner);

        Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() > 0);
    }
```

Acrescente os `use` que faltarem no topo do arquivo: `App\Exceptions\PlanLimitException`, `App\Models\TeamInvitation`, `Illuminate\Support\Facades\Bus`.

Se o arquivo não tiver `$this->team` e `$this->owner` como propriedades do `setUp()`, adapte para a forma que ele já usa — leia o `setUp()` existente antes de escrever.

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=batch tests/Feature/Queue/ImportInvitationsTest.php
```

Esperado: o primeiro falha porque nenhuma exceção é lançada e os convites são criados.

- [ ] **Step 3: Verificar o lote inteiro**

Em `app/Actions/Teams/ImportInvitations.php`, no início de `handle()`, antes de `$chunks = array_chunk(...)`:

```php
        $clean = $this->clean($emails);

        $billing = TeamBilling::for($team);

        // O lote inteiro ou nada. Aceitar até encher deixa a pergunta "quais
        // entraram", e não há tela que responda isso.
        if (! $billing->withinLimit('members', count($clean))) {
            throw PlanLimitException::reached('members', $billing->plan(), $billing->plan()->limit('members') ?? 0);
        }

        $chunks = array_chunk($clean, self::CHUNK);
```

Acrescente os `use` no topo e remova a chamada duplicada a `$this->clean($emails)` que existia dentro do `array_chunk`.

- [ ] **Step 4: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Queue/ImportInvitationsTest.php
```

- [ ] **Step 5: Red-check**

```bash
# Troque `count($clean)` por `1` e rode — um lote de 4 passaria a caber:
php artisan test --compact --filter=crosses_the_ceiling tests/Feature/Queue/ImportInvitationsTest.php   # deve FALHAR
# Desfaça e confirme verde.
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add app/Actions/Teams/ImportInvitations.php tests/Feature/Queue/ImportInvitationsTest.php
git commit -m "feat(billing): importação em lote respeita o teto do plano

Importar duzentos e-mails ignorava o limite por inteiro. E aqui a pergunta é
diferente do convite avulso: não é 'cabe mais um', é 'cabem mais N' — o
withinLimit já aceitava a quantidade e ninguém passava.

O lote é recusado inteiro. Aceitar até encher cria a pergunta 'quais entraram',
que a tela de convites não sabe responder."
```

---

### Task 4: `api` vira recurso verificado, e o catálogo para de vender o que não existe

**Files:**
- Create: `app/Exceptions/PlanFeatureException.php`
- Create: `app/Http/Middleware/EnsurePlanFeature.php`
- Modify: `bootstrap/app.php` (alias `plan-feature`)
- Modify: `routes/api.php` (grupo principal)
- Modify: `app/Support/Api/ApiProblem.php`
- Modify: `config/billing.php` (chaves `features`)
- Modify: `composer.json` (lista de classes do script `test:mutate`)
- Test: `tests/Feature/Billing/PlanFeatureTest.php`

**Interfaces:**
- Consumes: `TenantManager::current(): ?Team`, `Laravel\Pennant\Feature`, `TeamBilling::for()`.
- Produces: `App\Exceptions\PlanFeatureException::required(string $feature, Plan $plan): self`; middleware registrado com o alias `plan-feature`, usado como `plan-feature:api`.

- [ ] **Step 1: Escrever o teste que falha**

Crie `tests/Feature/Billing/PlanFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * The API is sold with the Pro plan and was available to everyone.
 *
 * The middleware order is a constraint and not a detail: the feature is scoped
 * to the team, and the team comes from the token. Asked before the tenant
 * exists, it answers about nobody.
 */
class PlanFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['billing.plans.pro.price_id' => 'price_pro']);
    }

    public function test_a_free_team_cannot_reach_the_api(): void
    {
        [$team, $token] = $this->tokenFor(subscribed: false);

        $this->withToken($token)
            ->getJson('/api/v1/team')
            ->assertStatus(402)
            ->assertJsonPath('title', 'plan_feature_required');

        $this->assertFalse(TeamBilling::for($team)->plan()->hasFeature('api'));
    }

    public function test_a_paying_team_reaches_the_api(): void
    {
        [, $token] = $this->tokenFor(subscribed: true);

        $this->withToken($token)->getJson('/api/v1/team')->assertOk();
    }

    /**
     * The support story the feature flags were built for: someone was promised
     * a capability their plan does not include, and the alternative is editing
     * the plan config for one account.
     */
    public function test_an_override_for_one_team_wins_over_the_plan(): void
    {
        [$team, $token] = $this->tokenFor(subscribed: false);

        Feature::for($team)->activate('api');

        $this->withToken($token)->getJson('/api/v1/team')->assertOk();
    }

    /**
     * If the check ran before the tenant was resolved it would ask about no
     * team at all, and a route that answers 402 for a paying customer is worse
     * than one that never checked.
     */
    public function test_the_feature_check_runs_after_the_tenant_is_resolved(): void
    {
        $middleware = collect(Route::getRoutes()->getByName('api.team.show')?->gatherMiddleware() ?? [])
            ->values()
            ->all();

        $tenant = array_search('App\Http\Middleware\SetTenantFromToken', $middleware, true);
        $feature = array_search('plan-feature:api', $middleware, true);

        $this->assertIsInt($tenant, 'O middleware de tenant não está na rota.');
        $this->assertIsInt($feature, 'O middleware de recurso de plano não está na rota.');
        $this->assertLessThan($feature, $tenant, 'O recurso de plano é verificado antes de o tenant existir.');
    }

    public function test_the_catalogue_only_names_features_the_product_delivers(): void
    {
        foreach (config('billing.plans') as $key => $plan) {
            $this->assertSame(
                [],
                array_diff($plan['features'], ['api']),
                "O plano [{$key}] anuncia recurso que o produto não verifica.",
            );
        }
    }

    /**
     * @return array{Team, string}
     */
    private function tokenFor(bool $subscribed): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['is_personal' => false, 'stripe_id' => 'cus_'.fake()->uuid()]);

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, TeamRole::Owner);

        if ($subscribed) {
            $team->subscriptions()->create([
                'type' => TeamBilling::SUBSCRIPTION,
                'stripe_id' => 'sub_'.fake()->uuid(),
                'stripe_status' => 'active',
                'stripe_price' => 'price_pro',
                'quantity' => 1,
            ]);
        }

        $token = $user->createToken('teste', ApiAbility::Read->implies());
        ApiToken::query()->whereKey($token->accessToken->getKey())->update(['team_id' => $team->id]);

        return [$team->refresh(), $token->plainTextToken];
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact tests/Feature/Billing/PlanFeatureTest.php
```

Esperado: o primeiro falha com 200 em vez de 402; o de ordem falha porque o middleware não existe; o de catálogo falha acusando `sso` e `audit-trail`.

- [ ] **Step 3: Criar a exceção**

`app/Exceptions/PlanFeatureException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Billing\Plan;
use RuntimeException;

/**
 * Raised when a team's plan does not include a capability it just asked for.
 *
 * Separate from PlanLimitException because the answer is different: a limit is
 * "you have used all of them", a feature is "your plan never had this". Naming
 * the plan that does is what turns the refusal into a next step.
 */
class PlanFeatureException extends RuntimeException
{
    public static function required(string $feature, Plan $plan): self
    {
        return new self(__('Your :plan plan does not include :feature.', [
            'plan' => $plan->name,
            'feature' => $feature,
        ]));
    }
}
```

- [ ] **Step 4: Criar o middleware**

`app/Http/Middleware/EnsurePlanFeature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\PlanFeatureException;
use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route whose capability the team's plan does not include.
 *
 * Asks Pennant rather than the plan directly, which is what lets support grant
 * a capability to one team without anyone editing the plan configuration for a
 * single account.
 *
 * Must run after whatever resolves the tenant. The capability is scoped to the
 * team, and on the API the team comes from the token — asked earlier, it would
 * answer about nobody.
 */
class EnsurePlanFeature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $team = resolve(TenantManager::class)->current();

        if ($team === null) {
            return $next($request);
        }

        if (! Feature::for($team)->active($feature)) {
            throw PlanFeatureException::required($feature, TeamBilling::for($team)->plan());
        }

        return $next($request);
    }
}
```

- [ ] **Step 5: Registrar o alias e aplicar na API**

Em `bootstrap/app.php`, no bloco `$middleware->alias([...])`, acrescente:

```php
            'plan-feature' => EnsurePlanFeature::class,
```

E o `use App\Http\Middleware\EnsurePlanFeature;` no topo.

Em `routes/api.php`, troque a linha do grupo principal:

```php
Route::middleware(['auth:sanctum', 'throttle:api', 'plan-feature:api'])->group(function (): void {
```

- [ ] **Step 6: Mapear a exceção**

Em `app/Support/Api/ApiProblem.php`, acrescente um braço ao `match (true)`, junto do que a Task 2 criou:

```php
            $e instanceof PlanFeatureException => new self(
                Response::HTTP_PAYMENT_REQUIRED,
                'plan_feature_required',
                $e->getMessage(),
            ),
```

E o `use App\Exceptions\PlanFeatureException;` no topo.

- [ ] **Step 7: Enxugar o catálogo de recursos**

Em `config/billing.php`: `pro` fica com `'features' => ['api']` e `business` fica com `'features' => ['api']`. `free` continua com `'features' => []`.

Acrescente ao bloco de comentário acima de `'plans' => [`:

```
    | Só entra aqui recurso que alguma parte do produto verifique. SSO e trilha
    | de auditoria saíram por isso: o SSO não existe, e a trilha só aparece no
    | painel do operador. Voltam quando houver o que entregar.
```

Em `composer.json`, no script `test:mutate`, acrescente `App\\Http\\Middleware\\EnsurePlanFeature,` à lista de `--class=`. O middleware decide acesso: pertence aos caminhos críticos que o mutation mede.

- [ ] **Step 8: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Billing/PlanFeatureTest.php tests/Feature/Api
```

Esperado: PASS. Testes existentes da API que usem time sem assinatura vão acusar — a API deixou de ser gratuita. Ajuste-os assinando o plano `pro` (o helper `tokenFor` acima mostra a forma), e não removendo o middleware.

- [ ] **Step 9: Red-check**

```bash
# Inverta a condição do middleware (`if (Feature::for($team)->active($feature))`) e rode:
php artisan test --compact --filter=paying_team tests/Feature/Billing/PlanFeatureTest.php   # deve FALHAR
# Desfaça. Mova 'plan-feature:api' para antes de 'auth:sanctum' em routes/api.php e rode:
php artisan test --compact --filter=after_the_tenant tests/Feature/Billing/PlanFeatureTest.php   # deve FALHAR
# Desfaça. Devolva 'sso' ao business na config e rode:
php artisan test --compact --filter=only_names_features tests/Feature/Billing/PlanFeatureTest.php   # deve FALHAR
# Desfaça tudo e confirme verde.
```

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add app/Exceptions/PlanFeatureException.php app/Http/Middleware/EnsurePlanFeature.php bootstrap/app.php routes/api.php app/Support/Api/ApiProblem.php config/billing.php tests/Feature/Billing/PlanFeatureTest.php
git commit -m "feat(billing): a API passa a exigir o plano que a vende, e o catálogo para de mentir

O recurso api era vendido no Pro e no Business e a API v1 não perguntava o plano
— time no gratuito criava token e usava tudo. O único efeito real do plano era o
teto de requisições.

A verificação pergunta ao Pennant e não ao plano direto, que é o que permite ao
suporte liberar um recurso para uma conta sem ninguém editar a config de planos.
Era a história que o docblock do PlanFeature já prometia e que nenhum consumidor
tornava verdadeira.

A ordem do middleware virou restrição testada: o recurso é do time, e o time vem
do token. Verificado antes do tenant, responderia sobre ninguém.

E saem do catálogo o sso, que não existe, e o audit-trail, que existe e só o
operador vê. Isso é o catálogo parando de mentir, não o produto encolhendo —
voltam quando houver o que entregar."
```

---

### Task 5: A tela de tokens diz por que não há formulário

**Files:**
- Modify: `resources/views/pages/settings/⚡api-tokens.blade.php`
- Test: `tests/Feature/Api/ApiTokenManagementTest.php` (acrescentar casos)

**Interfaces:**
- Consumes: `Laravel\Pennant\Feature::for($team)->active('api')`, `TeamBilling::for($team)->plan()`.
- Produces: nenhuma nova assinatura pública.

- [ ] **Step 1: Escrever o teste que falha**

Acrescente a `tests/Feature/Api/ApiTokenManagementTest.php` (adapte os helpers ao `setUp()` que o arquivo já tem):

```php
    /**
     * Disappearing without a word is what generates the support ticket. The
     * screen says which plan includes the API instead of hiding the form.
     */
    public function test_a_team_without_the_api_feature_is_told_why(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('api-tokens.edit'))
            ->assertOk()
            ->assertSee(__('Your :plan plan does not include :feature.', [
                'plan' => 'Free',
                'feature' => 'api',
            ]))
            ->assertDontSee('data-test="create-token"', escape: false);
    }
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=is_told_why tests/Feature/Api/ApiTokenManagementTest.php
```

- [ ] **Step 3: Ler a view antes de mudar**

```bash
sed -n 1,60p 'resources/views/pages/settings/⚡api-tokens.blade.php'
```

Localize o bloco do formulário de criação e o atributo `data-test` que ele já usa. O teste acima assume `data-test="create-token"` — **se o atributo real for outro, ajuste o teste para o atributo real** em vez de renomear o da view, que é referenciado por testes existentes.

- [ ] **Step 4: Envolver o formulário na verificação**

No componente Livewire da view, acrescente ao bloco PHP do topo:

```php
    #[Computed]
    public function hasApiAccess(): bool
    {
        $team = Auth::user()?->currentTeam;

        return $team !== null && Feature::for($team)->active('api');
    }
```

com os `use` de `Laravel\Pennant\Feature`, `Livewire\Attributes\Computed` e `Illuminate\Support\Facades\Auth` conforme já usados nos outros componentes de `settings`.

No markup, envolva o formulário e a lista de tokens:

```blade
@if ($this->hasApiAccess)
    {{-- formulário e lista existentes, sem alteração --}}
@else
    <flux:callout variant="warning" icon="lock-closed">
        <flux:callout.heading>{{ __('The API is not part of this plan') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Your :plan plan does not include :feature.', ['plan' => $this->plan->name, 'feature' => 'api']) }}
        </flux:callout.text>
        <x-slot name="actions">
            <flux:button :href="route('billing.edit')" variant="primary" wire:navigate>{{ __('See plans') }}</flux:button>
        </x-slot>
    </flux:callout>
@endif
```

Acrescente a propriedade computada `plan` que devolve `TeamBilling::for($team)->plan()`, seguindo o padrão que `⚡billing.blade.php` já usa. O `flux:callout` está na versão gratuita e já é usado em `⚡billing.blade.php`, `⚡recovery-codes.blade.php` e `⚡two-factor-setup-modal.blade.php` — copie a forma de um deles.

- [ ] **Step 5: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Api/ApiTokenManagementTest.php
```

- [ ] **Step 6: Red-check**

```bash
# Troque `@if ($this->hasApiAccess)` por `@if (true)` e rode:
php artisan test --compact --filter=is_told_why tests/Feature/Api/ApiTokenManagementTest.php   # deve FALHAR
# Desfaça e confirme verde.
```

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add 'resources/views/pages/settings/⚡api-tokens.blade.php' tests/Feature/Api/ApiTokenManagementTest.php
git commit -m "feat(billing): a tela de tokens diz por que o formulário não está lá

Sumir sem explicação é o que gera chamado. Quem está num plano sem API vê o
nome do plano, o recurso que falta e o caminho para os planos, em vez de uma
tela que perdeu metade do conteúdo sem motivo aparente."
```

---

### Task 6: O excedente fica visível, e a demonstração mostra esse estado

**Files:**
- Modify: `resources/views/pages/settings/⚡billing.blade.php`
- Modify: `database/seeders/DemoSeeder.php`
- Test: `tests/Feature/Billing/OverTheLimitTest.php` (novo)
- Test: `tests/Feature/DemoSeederTest.php` (acrescentar caso)

**Interfaces:**
- Consumes: `TeamBilling::usage(string $resource): array{limit: int|null, used: int, remaining: int|null}`.
- Produces: nenhuma nova assinatura pública.

- [ ] **Step 1: Escrever o teste que falha**

Crie `tests/Feature/Billing/OverTheLimitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A team can end up over its ceiling: the plan changes, the limit drops, and
 * whoever was already there stays. Nobody is removed for it — deciding who
 * leaves the team belongs to the customer — but the state has to be visible,
 * and it must not read like the suspension for an unpaid bill.
 */
class OverTheLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_nobody_is_removed_for_crossing_the_ceiling(): void
    {
        $team = $this->teamOverItsCeiling();

        $this->assertSame(5, $team->memberships()->count());
    }

    public function test_the_billing_screen_shows_the_excess(): void
    {
        $team = $this->teamOverItsCeiling();
        $owner = $team->members()->first();

        $owner->switchTeam($team);

        $this->actingAs($owner)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertSee(__('Above your plan'));
    }

    public function test_usage_reports_the_excess_without_going_negative(): void
    {
        $usage = TeamBilling::for($this->teamOverItsCeiling())->usage('members');

        $this->assertSame(3, $usage['limit']);
        $this->assertSame(5, $usage['used']);
        $this->assertSame(0, $usage['remaining']);
    }

    private function teamOverItsCeiling(): Team
    {
        $team = Team::factory()->create();

        foreach (range(1, 5) as $index) {
            $member = User::factory()->create();
            $team->members()->attach($member);
            $member->assignTeamRole($team, $index === 1 ? TeamRole::Owner : TeamRole::Member);
        }

        return $team->refresh();
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact tests/Feature/Billing/OverTheLimitTest.php
```

Esperado: o teste da tela falha por não encontrar o texto.

- [ ] **Step 3: Mostrar o excedente na tela**

Em `resources/views/pages/settings/⚡billing.blade.php`, no bloco que já exibe o uso, acrescente logo depois da barra ou do número de uso:

```blade
@if ($usage['limit'] !== null && $usage['used'] > $usage['limit'])
    <flux:badge color="amber" size="sm">{{ __('Above your plan') }}</flux:badge>
    <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
        {{ __(':used of :limit — upgrade the plan or free up seats.', ['used' => $usage['used'], 'limit' => $usage['limit']]) }}
    </flux:text>
@endif
```

Leia o bloco de uso existente antes de editar: a variável pode se chamar diferente e vir de `$this->usage()`. Use o nome que a view já usa.

O aviso é deliberadamente diferente do de suspensão por inadimplência, que já existe nessa tela: são estados diferentes, com causas diferentes, e as duas mensagens não podem parecer a mesma coisa.

- [ ] **Step 4: Acrescentar o time acima do teto à demonstração**

Em `database/seeders/DemoSeeder.php`, acrescente à constante `TEAMS`:

```php
        ['slug' => 'hooli', 'name' => 'Hooli', 'status' => 'none', 'price' => 'free', 'plan' => 'Gratuito acima do teto'],
```

e a `PEOPLE`, quatro pessoas em `hooli` (uma `Owner`, três `Member`), o que com o teto de três do plano gratuito deixa o time visivelmente acima.

No método `subscriptions()`, pule os times cujo `status` seja `none`:

```php
        foreach (self::TEAMS as $definition) {
            // Time sem assinatura existe de propósito na demonstração: é o que
            // mostra a tela de excedente, que só aparece no plano gratuito.
            if ($definition['status'] === 'none') {
                continue;
            }
```

Acrescente a linha correspondente na tabela do `report()`.

> Nota: o seeder liga membros com `$team->members()->attach()`, que não passa pela action — então ele não é barrado pelo limite da Task 2. Isso é intencional: o estado "acima do teto" precisa ser criável para ser demonstrável.

- [ ] **Step 5: Ajustar o teste do seeder**

Em `tests/Feature/DemoSeederTest.php`, atualize `test_it_creates_the_teams_people_and_subscription_states` para 4 times e 14 pessoas, e acrescente:

```php
    public function test_it_seeds_a_team_above_its_plan_ceiling(): void
    {
        $this->seed(DemoSeeder::class);

        $hooli = Team::query()->where('slug', 'hooli')->firstOrFail();
        $usage = TeamBilling::for($hooli)->usage('members');

        $this->assertGreaterThan($usage['limit'], $usage['used']);
    }
```

com o `use App\Support\Billing\TeamBilling;` no topo.

- [ ] **Step 6: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Billing/OverTheLimitTest.php tests/Feature/DemoSeederTest.php
```

- [ ] **Step 7: Red-check**

```bash
# Troque a condição da view por `@if (false)` e rode:
php artisan test --compact --filter=shows_the_excess tests/Feature/Billing/OverTheLimitTest.php   # deve FALHAR
# Desfaça. Tire uma pessoa do hooli no seeder e rode:
php artisan test --compact --filter=above_its_plan_ceiling tests/Feature/DemoSeederTest.php       # deve FALHAR
# Desfaça e confirme verde.
```

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add 'resources/views/pages/settings/⚡billing.blade.php' database/seeders/DemoSeeder.php tests/Feature/Billing/OverTheLimitTest.php tests/Feature/DemoSeederTest.php
git commit -m "feat(billing): quem está acima do teto vê que está, e ninguém é removido

Dá para chegar acima do teto rebaixando de plano, ou o teto mudando embaixo de
quem já estava. Até agora usage() respondia 'remaining: 0' e nada na tela dizia
que estava estourado.

Ninguém é removido automaticamente: decidir quem sai do time é decisão do
cliente, e o produto que decide isso sozinho apaga acesso de gente sem aviso. O
que muda é que adicionar está bloqueado e o excedente aparece, com os dois
caminhos — subir de plano ou liberar assentos.

O aviso é diferente do de suspensão por inadimplência de propósito: são estados
diferentes, e as duas mensagens não podem parecer a mesma coisa.

A demonstração ganhou um time nesse estado, porque tela que ninguém vê rodando
é tela que quebra sem ninguém notar."
```

---

### Task 7: Verificação final e registro

**Files:**
- Modify: `PLANEJAMENTO.md`

**Interfaces:**
- Consumes: nada.
- Produces: nada.

- [ ] **Step 1: Rodar a verificação completa**

```bash
composer check
composer test:browser
```

Esperado: exit 0 nos dois. Se `test:browser` acusar, verifique se algum fluxo de convite na tela passou a esbarrar no teto do plano gratuito — o time do teste de browser é pessoal e tem um membro só, então não deveria.

- [ ] **Step 2: Conferir a cobertura e a catraca de mutation**

```bash
# Exige driver de cobertura. No contêiner:
docker compose exec -T -u 1000 -e DB_HOST=pgsql -e REDIS_HOST=redis -e DB_DATABASE=testing laravel.test composer test:coverage
docker compose exec -T -u 1000 -e DB_HOST=pgsql -e REDIS_HOST=redis -e DB_DATABASE=testing laravel.test composer test:mutate
```

Esperado: cobertura acima de 85% e MSI acima de 97. A remoção do `EnsureWithinPlanLimits` tira da medição um arquivo que estava em 0% de cobertura, então os dois números tendem a subir. Se o MSI subir de forma estável, suba a catraca no `composer.json` para o novo piso inteiro — a catraca só vale se acompanhar.

- [ ] **Step 3: Registrar o resultado**

Em `PLANEJAMENTO.md`, acrescente uma seção nova depois da Fase 10, antes do `## 5. Cronograma`:

```markdown
### Depois do plano — Fase 0 da cobrança: ligar o plano ao produto

O levantamento que originou este trabalho achou um tema, não itens soltos: a
máquina de cobrar por recurso estava construída e não estava plugada em nada.
A API não perguntava o plano, o teto de membros não era verificado em lugar
nenhum, e o catálogo vendia SSO e trilha de auditoria que o produto não entrega.

[Complete com os números reais: testes, cobertura, MSI, e os achados que
aparecerem durante a implementação.]
```

Substitua o trecho entre colchetes pelos números reais — o placeholder não pode ficar.

- [ ] **Step 4: Commit**

```bash
git add PLANEJAMENTO.md composer.json
git commit -m "docs: registra o resultado da fase 0 da cobrança"
```

---

## Auto-revisão do plano

**Cobertura da especificação:** D1 na Task 2 (verificação na action, middleware removido); D2 na Task 1 (convites pendentes contam); D3 na Task 3 (lote inteiro); D4 na Task 1 (registro e teste de consistência); D5 na Task 4 (`api` verificado, ordem testada); D6 nas Tasks 2 e 6 (adição bloqueada, ninguém removido, excedente visível, rebaixar permitido). Catálogo enxuto: limites na Task 1, recursos na Task 4. Tela de tokens na Task 5. `CLAUDE.md` na Task 1. Risco do seeder na Task 6.

**Item da especificação sem tarefa:** "rebaixar de plano estando acima do teto é permitido" não ganha código nesta fase — não existe fluxo de rebaixamento no produto ainda, ele é da fase seguinte. O que esta fase garante é que nada bloqueia o estado resultante, e o `OverTheLimitTest` prova que o time acima do teto funciona.

**Consistência de tipos:** `CountsPlanResource::count(Team): int` é implementado por `MemberCounter` e consumido por `ResourceCounters::countFor(string, Team): int`, chamado por `TeamBilling::countOf(string): int`. `PlanLimitException::reached(string, Plan, int)` e `PlanFeatureException::required(string, Plan)` são usadas nas Tasks 2, 3 e 4 com essas assinaturas exatas. O alias `plan-feature` é registrado na Task 4 e usado só lá.
