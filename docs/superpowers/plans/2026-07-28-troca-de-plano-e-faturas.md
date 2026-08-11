# Troca de plano e faturas — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trocar de plano de dentro do produto, com proporção, e mostrar as faturas que a tela promete há três fases.

**Architecture:** A troca vira ação do Livewire chamando uma action própria — e não um link `GET`, porque com `swap()` o link passaria a mudar o plano de verdade, sem CSRF e sujeito a prefetch. O checkout hospedado continua link, já que ali o `GET` só inicia um fluxo. As faturas são lidas ao vivo da Stripe, e o download passa obrigatoriamente por `downloadInvoice()`, que é o único caminho que verifica o dono.

**Tech Stack:** Laravel 13, PHP 8.4, Cashier 16, Pest 4 (testes em classes no estilo PHPUnit), Livewire 4 + Flux, PostgreSQL.

**Especificação:** [`docs/superpowers/specs/2026-07-28-troca-de-plano-e-faturas-design.md`](../specs/2026-07-28-troca-de-plano-e-faturas-design.md)

## Global Constraints

- **Todo teste novo precisa ser visto falhar antes de valer.** Quebre o código que ele cobre, confirme que acusa, desfaça, anote no relatório.
- **Docblocks `/** */` em inglês; comentários `//` e `/* */` em português; nomes de teste em inglês.** O Peck roda no CI com dicionário inglês americano — evite grafia britânica e possessivo com nome de pacote.
- **Comentário explica o porquê, não o quê.**
- **`composer check` com exit 0** ao fim de cada tarefa.
- **`php artisan test` sem caminho trava** — inclui a suíte `Browser`, cujo servidor do Playwright não encerra. Use `composer test` ou passe caminho. Se rodar `composer test:browser`, **redirecione para arquivo**, não canalize.
- **Catraca de mutation em `--min=95`.** A tarefa 4 confere.
- **Actions não autorizam; quem chama autoriza.** É a convenção do projeto (`InviteTeamMember` não chama `Gate`; o controller da API e o componente chamam).
- **Nada de abstração sem três usos reais.**

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/Actions/Billing/SwapPlan.php` (novo) | Troca a assinatura do time para outro plano, com proporção |
| `app/Http/Controllers/BillingController.php` | `checkout` recusa time que já assina; ganha `invoice` para download |
| `routes/settings.php` | Rota do download de fatura |
| `resources/views/pages/settings/⚡billing.blade.php` | Botão vira ação para quem já assina; confirmação de rebaixamento; lista de faturas |
| `tests/Feature/Billing/SwapPlanTest.php` (novo) | A troca, as recusas e a autorização |
| `tests/Feature/Billing/InvoicesTest.php` (novo) | Listagem, estado vazio e o 403 entre times |
| `tests/Feature/Billing/StripeIntegrationTest.php` | Um caso de `swap()` contra a conta de teste |

---

### Task 1: A action de troca de plano

**Files:**
- Create: `app/Actions/Billing/SwapPlan.php`
- Test: `tests/Feature/Billing/SwapPlanTest.php`

**Interfaces:**
- Consumes: `App\Support\Billing\TeamBilling::SUBSCRIPTION` (constante com o nome do tipo de assinatura), `TeamBilling::for(Team): self`, `Plan` com as propriedades públicas `key`, `name`, `priceId`.
- Produces: `App\Actions\Billing\SwapPlan::handle(Team $team, Plan $plan): void` — lança `App\Exceptions\PlanSwapException` nos casos recusados.
- Create também: `app/Exceptions/PlanSwapException.php` com `samePlan(Plan $plan): self` e `notSubscribed(): self`.

- [ ] **Step 1: Escrever o teste que falha**

Crie `tests/Feature/Billing/SwapPlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Billing\SwapPlan;
use App\Exceptions\PlanSwapException;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Swapping used to be impossible from inside the product: the checkout route
 * called newSubscription() without looking at whether one already existed, so
 * a subscribed team clicking another plan started a second subscription flow
 * instead of moving.
 */
class SwapPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.plans.pro.price_id' => 'price_pro',
            'billing.plans.business.price_id' => 'price_business',
        ]);
    }

    public function test_it_refuses_a_team_with_no_subscription(): void
    {
        $this->expectException(PlanSwapException::class);

        resolve(SwapPlan::class)->handle(Team::factory()->create(), Plan::find('pro'));
    }

    public function test_it_refuses_the_plan_the_team_is_already_on(): void
    {
        $team = $this->subscribed('price_pro');

        $this->expectException(PlanSwapException::class);

        resolve(SwapPlan::class)->handle($team, Plan::find('pro'));
    }

    /**
     * The message names the plan, because "that did not work" with no noun is
     * the kind of refusal people open a ticket about.
     */
    public function test_the_refusal_names_the_plan(): void
    {
        $team = $this->subscribed('price_pro');

        try {
            resolve(SwapPlan::class)->handle($team, Plan::find('pro'));
            $this->fail('A troca deveria ter sido recusada.');
        } catch (PlanSwapException $e) {
            $this->assertStringContainsString('Pro', $e->getMessage());
        }
    }

    private function subscribed(string $price): Team
    {
        $team = Team::factory()->create(['is_personal' => false, 'stripe_id' => 'cus_'.fake()->uuid()]);

        $team->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_'.fake()->uuid(),
            'stripe_status' => 'active',
            'stripe_price' => $price,
            'quantity' => 1,
        ]);

        return $team->refresh();
    }
}
```

> **Nota sobre o que estes testes cobrem.** Eles cobrem as **recusas**, que são
> lógica nossa. A troca bem-sucedida chama a Stripe e **não** é testável offline
> — ela vive no `StripeIntegrationTest` da tarefa 4. Não escreva mock do
> `swap()`: teste que afirma que um mock foi chamado não prova que a assinatura
> mudou, e este projeto não tem esse tipo de teste em lugar nenhum.

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact tests/Feature/Billing/SwapPlanTest.php
```

Esperado: erro de classe inexistente (`App\Actions\Billing\SwapPlan`).

- [ ] **Step 3: Criar a exceção**

`app/Exceptions/PlanSwapException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Billing\Plan;
use RuntimeException;

/**
 * Raised when a plan change cannot be applied to the subscription it names.
 *
 * Separate from PlanLimitException and PlanFeatureException because the answer
 * is a third one: not "you have used all of them" and not "your plan never had
 * this", but "this change does not apply".
 */
class PlanSwapException extends RuntimeException
{
    public static function samePlan(Plan $plan): self
    {
        return new self(__('This team is already on the :plan plan.', ['plan' => $plan->name]));
    }

    public static function notSubscribed(): self
    {
        return new self(__('This team has no subscription to change.'));
    }
}
```

- [ ] **Step 4: Criar a action**

`app/Actions/Billing/SwapPlan.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Exceptions\PlanSwapException;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;

/**
 * Moves a subscribed team to another plan, prorated.
 *
 * Proration is Stripe's default and we keep it: `noProrate()` is deliberately
 * not called. Going up bills the difference, going down leaves a credit on the
 * next invoice — one code path for both directions, which is what keeps the
 * screen able to explain what will happen.
 *
 * Does not authorize. The caller does, as everywhere else in app/Actions.
 */
class SwapPlan
{
    public function handle(Team $team, Plan $plan): void
    {
        $subscription = $team->subscription(TeamBilling::SUBSCRIPTION);

        if ($subscription === null) {
            throw PlanSwapException::notSubscribed();
        }

        if (TeamBilling::for($team)->plan()->key === $plan->key) {
            throw PlanSwapException::samePlan($plan);
        }

        if ($plan->priceId === null) {
            throw PlanSwapException::notSubscribed();
        }

        $subscription->swap($plan->priceId);
    }
}
```

> Se o tipo devolvido por `$team->subscription()` não for nulável segundo o
> PHPStan, ajuste a verificação para a forma que o `TeamBilling` já usa ao
> consultar a assinatura — leia `app/Support/Billing/TeamBilling.php` antes.

- [ ] **Step 5: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Billing/SwapPlanTest.php
```

- [ ] **Step 6: Red-check**

```bash
# Troque `throw PlanSwapException::notSubscribed()` da assinatura ausente por um `return`:
php artisan test --compact --filter=refuses_a_team_with_no_subscription tests/Feature/Billing/SwapPlanTest.php   # deve FALHAR
# Desfaça. Remova a comparação de plano igual:
php artisan test --compact --filter=already_on tests/Feature/Billing/SwapPlanTest.php                            # deve FALHAR
# Desfaça e confirme verde.
```

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add app/Actions/Billing app/Exceptions/PlanSwapException.php tests/Feature/Billing/SwapPlanTest.php
git commit -m "feat(billing): action de troca de plano, com proporção

A proporção é o padrão da Stripe e fica: noProrate() não é chamado. Subir cobra a
diferença, descer deixa crédito na próxima fatura — um caminho de código para os
dois sentidos, que é o que permite a tela explicar o que vai acontecer.

Os testes cobrem as recusas, que são lógica nossa. A troca bem-sucedida chama a
Stripe e vive no teste de integração opt-in: mock de swap() afirmaria que um
método foi chamado, não que a assinatura mudou."
```

---

### Task 2: A tela troca de plano, e avisa antes de rebaixar

**Files:**
- Modify: `resources/views/pages/settings/⚡billing.blade.php`
- Modify: `app/Http/Controllers/BillingController.php` (método `checkout`)
- Test: `tests/Feature/Billing/SwapPlanTest.php` (acrescentar casos de tela)

**Interfaces:**
- Consumes: `SwapPlan::handle(Team, Plan): void`, `PlanSwapException`, `TeamBilling::usage('members')`, `Plan::limit('members'): ?int`.
- Produces: nenhuma assinatura pública nova.

- [ ] **Step 1: Escrever os testes que falham**

Acrescente a `tests/Feature/Billing/SwapPlanTest.php` (use `Livewire\Livewire` e confira o nome do componente em `routes/settings.php`, que é `pages::settings.billing`):

> **A troca bem-sucedida NÃO é testável aqui, pelo mesmo motivo da tarefa 1:**
> `choose()` chama a action, que chama `swap()`, que fala com a Stripe. Um teste
> de tela que "troca com sucesso" vai tentar sair para a rede e falhar. Os testes
> abaixo cobrem **autorização** e **o fluxo de confirmação**, que são lógica
> nossa; a troca de verdade fica no teste de integração da tarefa 4. Se você se
> pegar escrevendo mock do `swap()` para fazer um teste de tela passar, pare —
> ele provaria que um método foi chamado, não que o plano mudou.

```php
    public function test_someone_without_the_billing_permission_cannot_swap(): void
    {
        $team = $this->subscribed('price_pro');
        $member = $this->memberOf($team);

        Livewire::actingAs($member)
            ->test('pages::settings.billing')
            ->call('choose', 'business')
            ->assertForbidden();
    }

    /**
     * Dropping to a plan the team no longer fits in is allowed — blocking it
     * traps whoever wants to pay less. What is not allowed is doing it in
     * silence.
     */
    public function test_dropping_below_the_current_headcount_asks_first(): void
    {
        $team = $this->subscribed('price_business');
        $owner = $this->ownerOf($team);

        foreach (range(1, 4) as $ignored) {
            $team->members()->attach(\App\Models\User::factory()->create());
        }

        Livewire::actingAs($owner)
            ->test('pages::settings.billing')
            ->call('choose', 'free')
            ->assertSet('confirming', 'free')
            ->assertSee('5');
    }

    public function test_dropping_within_the_headcount_does_not_ask(): void
    {
        $team = $this->subscribed('price_business');
        $owner = $this->ownerOf($team);

        Livewire::actingAs($owner)
            ->test('pages::settings.billing')
            ->call('choose', 'pro')
            ->assertSet('confirming', null);
    }
```

Escreva os auxiliares `ownerOf()` e `memberOf()` seguindo o padrão de
`tests/Feature/Billing/SuspensionTest.php::actingAsMemberOf()`, que já monta
membership, papel e `switchTeam`. **Leia-o antes de escrever o seu.**

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact tests/Feature/Billing/SwapPlanTest.php
```

Esperado: método `choose` inexistente no componente.

- [ ] **Step 3: Trocar o link pela ação, no componente**

Leia o bloco de planos da view (por volta da linha 140) antes de editar. O botão
de escolher plano passa a ter duas formas:

```blade
@if ($this->billing->subscribed())
    <flux:button wire:click="choose('{{ $key }}')" variant="primary" size="sm" class="w-full" :data-test="'billing-choose-'.$key">
        {{ __('Choose :plan', ['plan' => $plan->name]) }}
    </flux:button>
@else
    {{-- Sem assinatura o caminho é o checkout hospedado da Stripe, e ali o GET
         só inicia o fluxo: nada muda deste lado. --}}
    <flux:button :href="route('billing.checkout', ['plan' => $key])" variant="primary" size="sm" class="w-full" :data-test="'billing-choose-'.$key">
        {{ __('Choose :plan', ['plan' => $plan->name]) }}
    </flux:button>
@endif
```

No bloco PHP do componente, acrescente a propriedade e os dois métodos:

```php
    public ?string $confirming = null;

    /**
     * Start a plan change, asking first when the new plan is smaller than what
     * the team is already using.
     */
    public function choose(string $key): void
    {
        $team = $this->team();

        abort_unless($this->canManage, 403);

        $plan = Plan::purchasable()[$key] ?? Plan::find($key);

        if ($this->wouldLeaveTeamOverTheCeiling($plan) && $this->confirming !== $key) {
            $this->confirming = $key;

            return;
        }

        $this->confirming = null;

        try {
            resolve(SwapPlan::class)->handle($team, $plan);
        } catch (PlanSwapException $e) {
            $this->addError('plan', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Plan changed to :plan.', ['plan' => $plan->name]));
    }

    private function wouldLeaveTeamOverTheCeiling(Plan $plan): bool
    {
        $limit = $plan->limit('members');

        return $limit !== null && $this->usage['members']['used'] > $limit;
    }
```

Ajuste `$this->usage['members']` ao formato real que a propriedade computada
`usage()` devolve — **leia-a antes**; ela pode devolver lista de linhas em vez de
mapa por recurso.

No markup, acrescente o bloco de confirmação, que só aparece quando há plano em
confirmação:

```blade
@if ($this->confirming)
    <flux:callout variant="warning" icon="exclamation-triangle" data-test="downgrade-confirm">
        <flux:callout.heading>{{ __('This plan is smaller than what the team uses') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __(':used of :limit seats in use. Nobody is removed, and no one new can be added until seats are freed.', [
                'used' => $this->usage['members']['used'],
                'limit' => Plan::find($this->confirming)->limit('members'),
            ]) }}
        </flux:callout.text>
        <x-slot name="actions">
            <flux:button wire:click="choose('{{ $this->confirming }}')" variant="danger" size="sm" data-test="downgrade-confirm-button">{{ __('Change anyway') }}</flux:button>
            <flux:button wire:click="$set('confirming', null)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
        </x-slot>
    </flux:callout>
@endif
```

- [ ] **Step 4: O checkout recusa quem já assina**

Em `app/Http/Controllers/BillingController.php`, no início de `checkout()`, depois
de resolver o time:

```php
        // Quem já assina troca pela tela, que passa pela action e pela
        // confirmação. Chegar aqui assinado significa link antigo ou URL
        // digitada — e seguir criaria uma segunda assinatura.
        if (TeamBilling::for($team)->subscribed()) {
            return back()->withErrors(['plan' => __('This team already has a subscription. Change the plan from the billing page.')]);
        }
```

- [ ] **Step 5: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Billing
```

- [ ] **Step 6: Red-check**

```bash
# Remova o abort_unless do choose():
php artisan test --compact --filter=without_the_billing_permission tests/Feature/Billing/SwapPlanTest.php   # deve FALHAR
# Desfaça. Faça wouldLeaveTeamOverTheCeiling devolver sempre false:
php artisan test --compact --filter=dropping_below tests/Feature/Billing/SwapPlanTest.php                   # deve FALHAR
# Desfaça. Faça-a devolver sempre true:
php artisan test --compact --filter=dropping_within tests/Feature/Billing/SwapPlanTest.php                  # deve FALHAR
# Desfaça e confirme verde.
```

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add resources/views/pages/settings app/Http/Controllers/BillingController.php tests/Feature/Billing/SwapPlanTest.php
git commit -m "feat(billing): trocar de plano pela tela, com aviso antes de rebaixar

O botão de plano era um link GET para o checkout. Isso funcionava enquanto o GET
só iniciava um fluxo hospedado; com swap() ele passaria a mudar o plano de
verdade, sem CSRF e sujeito a um prefetch de navegador trocar o plano de alguém
sem clique. Para quem já assina, virou ação do Livewire.

O aviso de rebaixamento aparece só quando o plano novo é menor que o consumo
atual. Pedir confirmação em toda troca treina a pessoa a clicar sem ler, que é o
contrário do que a confirmação existe para fazer."
```

---

### Task 3: As faturas que a tela promete há três fases

**Files:**
- Modify: `app/Http/Controllers/BillingController.php` (método `invoice`)
- Modify: `routes/settings.php`
- Modify: `resources/views/pages/settings/⚡billing.blade.php`
- Test: `tests/Feature/Billing/InvoicesTest.php` (novo)

**Interfaces:**
- Consumes: `$team->invoices(): Collection<Invoice>` e `$team->downloadInvoice(string $id): Response` do Cashier.
- Produces: rota nomeada `billing.invoice`, com parâmetro `{invoice}`.

- [ ] **Step 1: Escrever o teste que falha**

Crie `tests/Feature/Billing/InvoicesTest.php`. O teste que mais importa é o do
403 entre times — os demais cobrem o estado vazio, que é o único caminho
testável offline:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screen has promised "Plan, usage and invoices for this team" since the
 * billing phase and listed none.
 *
 * Reading is live from Stripe, so the happy path is not testable offline. What
 * is testable — and is the part that would leak — is that one team cannot reach
 * another team's invoice.
 */
class InvoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_team_with_no_billing_account_sees_an_empty_state(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $owner = $this->ownerOf($team);

        $this->actingAs($owner)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertSee(__('No invoices yet'));
    }

    /**
     * Cashier throws InvalidInvoice when the invoice belongs to someone else,
     * and findInvoiceOrFail turns that into a 403. The plain findInvoice does
     * not — using it here would hand one team another team's invoice.
     */
    public function test_a_team_cannot_download_another_teams_invoice(): void
    {
        $team = Team::factory()->create(['is_personal' => false, 'stripe_id' => 'cus_ours']);
        $owner = $this->ownerOf($team);

        $this->actingAs($owner)
            ->get(route('billing.invoice', ['invoice' => 'in_someone_elses']))
            ->assertStatus(403);
    }

    public function test_someone_without_the_billing_permission_cannot_download(): void
    {
        $team = Team::factory()->create(['is_personal' => false, 'stripe_id' => 'cus_ours']);
        $member = $this->memberOf($team);

        $this->actingAs($member)
            ->get(route('billing.invoice', ['invoice' => 'in_whatever']))
            ->assertForbidden();
    }

    private function ownerOf(Team $team): User
    {
        return $this->memberOf($team, TeamRole::Owner);
    }

    private function memberOf(Team $team, TeamRole $role = TeamRole::Member): User
    {
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, $role);
        $user->switchTeam($team);

        return $user;
    }
}
```

> **Atenção ao teste do 403.** Sem chave da Stripe configurada no ambiente de
> teste, a chamada a `downloadInvoice()` vai falhar antes de chegar à verificação
> de dono — provavelmente com erro de autenticação da Stripe, não com 403.
> **Rode o teste e veja o que realmente acontece antes de decidir a forma final.**
> Se for esse o caso, o teste honesto é outro: afirme que o controller usa
> `downloadInvoice()` e não `findInvoice()`, ou mova o caso para o
> `StripeIntegrationTest` da tarefa 4. **Não** faça o teste passar capturando a
> exceção da Stripe e devolvendo 403 — isso transformaria "a Stripe está fora"
> em "acesso negado". Registre no relatório o que você encontrou e o que decidiu.

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact tests/Feature/Billing/InvoicesTest.php
```

- [ ] **Step 3: A rota e o controller**

Em `routes/settings.php`, junto das outras rotas de cobrança:

```php
    Route::get('settings/billing/invoice/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoice');
```

Em `BillingController`:

```php
    public function invoice(Request $request, string $invoice): Response
    {
        $team = $this->authorizedTeam();

        // downloadInvoice() e não findInvoice(): só o primeiro passa pelo
        // findInvoiceOrFail, que é onde o Cashier confere o dono e devolve 403.
        return $team->downloadInvoice($invoice, [
            'vendor' => config('app.name'),
            'product' => __('Subscription'),
        ]);
    }
```

Ajuste o tipo de retorno ao que `downloadInvoice()` devolve de fato — leia a
assinatura em `vendor/laravel/cashier/src/Concerns/ManagesInvoices.php`.

- [ ] **Step 4: A listagem na tela**

Acrescente uma propriedade computada ao componente:

```php
    /**
     * @return \Illuminate\Support\Collection<int, \Laravel\Cashier\Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        $team = $this->team();

        // Leitura ao vivo: a Stripe é a fonte de verdade de fatura, e guardar
        // cópia local só cria uma tabela para divergir dela. A seção falha
        // sozinha se a Stripe estiver fora — a tela continua de pé.
        try {
            return $team->invoices();
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }
```

E a seção no markup, com data, valor, situação e download, mais o estado vazio
com o texto `No invoices yet`. Siga o padrão visual das outras seções da tela.

- [ ] **Step 5: Rodar e confirmar que passa**

```bash
php artisan test --compact tests/Feature/Billing
```

- [ ] **Step 6: Red-check**

```bash
# Remova o authorizedTeam() do método invoice (deixe pegar o time direto):
php artisan test --compact --filter=without_the_billing_permission tests/Feature/Billing/InvoicesTest.php  # deve FALHAR
# Desfaça. Faça a computed invoices() devolver uma coleção com um item falso:
php artisan test --compact --filter=empty_state tests/Feature/Billing/InvoicesTest.php                     # deve FALHAR
# Desfaça e confirme verde.
```

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
composer check
git add app/Http/Controllers/BillingController.php routes/settings.php resources/views/pages/settings tests/Feature/Billing/InvoicesTest.php
git commit -m "feat(billing): a tela passa a listar as faturas que promete

O subtítulo dizia 'Plan, usage and invoices for this team' desde a fase 5 e não
listava nenhuma.

A leitura é ao vivo: a Stripe é a fonte de verdade de fatura, e cópia local só
cria tabela para divergir. A seção falha sozinha se a Stripe estiver fora — a
tela continua de pé, porque uma seção indisponível não deve derrubar a página.

O download passa por downloadInvoice(), nunca por findInvoice(): só o primeiro
chega ao findInvoiceOrFail, que é onde o Cashier confere o dono. Usar o outro
seria entregar a fatura de um time para outro."
```

---

### Task 4: Verificação contra a Stripe de verdade, e registro

**Files:**
- Modify: `tests/Feature/Billing/StripeIntegrationTest.php`
- Modify: `PLANEJAMENTO.md`

**Interfaces:** nenhuma.

- [ ] **Step 1: O caso de troca no teste opt-in**

Acrescente a `StripeIntegrationTest` — leia o `setUp()` dele antes; ele já pula
quando falta `STRIPE_INTEGRATION` ou chave, e já limpa os clientes que cria:

```php
    /**
     * The one part of a plan change that fixtures cannot answer: whether Stripe
     * accepts it and the subscription really moves. Proration is money, and no
     * offline test exercises the arithmetic.
     */
    public function test_a_subscription_really_moves_between_plans(): void
    {
        $plans = array_values(Plan::purchasable());

        if (count($plans) < 2) {
            $this->markTestSkipped('São necessários dois planos com price id para trocar entre eles.');
        }

        [$from, $to] = $plans;

        $team = Team::factory()->create(['is_personal' => false]);
        $team->createAsStripeCustomer();
        $this->customersCreated[] = $team->stripe_id;

        $team->newSubscription(TeamBilling::SUBSCRIPTION, $from->priceId)
            ->trialDays(14)
            ->add();

        resolve(SwapPlan::class)->handle($team->refresh(), $to);

        $this->assertSame($to->priceId, $team->refresh()->subscription(TeamBilling::SUBSCRIPTION)?->stripe_price);
    }
```

- [ ] **Step 2: Rodar contra a conta de teste**

```bash
STRIPE_INTEGRATION=true php artisan test --compact --filter=StripeIntegration
```

Esperado: todos passam. Se a troca falhar, **é achado e não ajuste de teste** —
registre o que a Stripe respondeu e pare para reportar.

- [ ] **Step 3: A verificação completa**

```bash
composer check
composer test:browser > /tmp/browser.log 2>&1; echo "EXIT=$?"; tail -20 /tmp/browser.log
```

E, no contêiner, cobertura e mutation:

```bash
docker compose exec -T -u 1000 -e DB_HOST=pgsql -e REDIS_HOST=redis -e DB_DATABASE=testing -e VIEW_COMPILED_PATH=/var/www/html/storage/framework/views-container laravel.test composer test:coverage
docker compose exec -T -u 1000 -e DB_HOST=pgsql -e REDIS_HOST=redis -e DB_DATABASE=testing -e VIEW_COMPILED_PATH=/var/www/html/storage/framework/views-container laravel.test composer test:mutate
```

O mutation leva cerca de 30 minutos. **Rode em primeiro plano, redirecionando
para arquivo**, e espere. Acrescente `App\\Actions\\Billing\\SwapPlan` à lista
`--class=` do `test:mutate` antes de medir — é o novo ponto de decisão desta
fase, e ponto de decisão fora da medição é como o número sobe sem a suíte
melhorar. Ao final, limpe o diretório temporário de views.

- [ ] **Step 4: Registrar**

Acrescente ao `PLANEJAMENTO.md`, na seção "Depois do plano", uma entrada com: os
números medidos, o resultado do teste de integração contra a Stripe (**dizendo
explicitamente se rodou ou se foi pulado por falta de chave**), e os achados que
aparecerem. Se a catraca precisar mudar de patamar por causa da classe nova,
diga o número medido e o motivo — nunca só o número novo.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Billing/StripeIntegrationTest.php PLANEJAMENTO.md composer.json
git commit -m "docs: registra o resultado da troca de plano e das faturas"
```

---

## Auto-revisão do plano

**Cobertura da especificação:** D1 (proporção imediata) na Task 1, com a
verificação real na Task 4; D2 (faturas ao vivo) na Task 3; D3 (`downloadInvoice`
e não `findInvoice`) na Task 3, com teste próprio; D4 (aviso antes de rebaixar)
na Task 2. O escopo excluído — cupom, imposto, plano anual, agendamento — não
aparece em tarefa nenhuma, como deve.

**Defeito corrigido antes da execução:** a primeira versão da Task 2 pedia um
teste de tela que trocasse o plano com sucesso — o que sairia para a rede, pelo
mesmo motivo que a Task 1 já registrava para a action. Removido: a tela é testada
na autorização e na confirmação, e a troca de verdade fica no teste opt-in.

**Risco que o plano assume:** o teste do 403 entre times pode não ser alcançável
offline, porque a chamada à Stripe falha antes da verificação de dono. A Task 3
manda descobrir isso rodando, e **proíbe** a saída fácil de capturar a exceção da
Stripe e devolver 403 — que transformaria indisponibilidade em negação de acesso.
Se o caso migrar para o teste opt-in, isso é resultado legítimo e precisa estar
no relatório.

**Consistência de tipos:** `SwapPlan::handle(Team, Plan): void` é produzido na
Task 1 e consumido nas Tasks 2 e 4 com essa assinatura exata.
`PlanSwapException::samePlan(Plan)` e `::notSubscribed()` idem.
