# Fase B — Carteira e razão de créditos — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Uma carteira em centavos por time, com razão append-only e reserva (holds), plugada na execução de consulta de modo que só o resultado útil debita — correto sob concorrência.

**Architecture:** Módulo `Carteira` (via `make:module`, fronteira testada). `Wallet` (um por time, saldo em centavos), `WalletTransaction` (razão append-only, cada linha grava `balance_after_cents`), `WalletHold` (reserva que reduz o saldo disponível). Quatro actions do módulo — `CreditWallet`, `ReserveCredit`, `ConfirmHold`, `ReleaseHold` — cada uma numa transação com `lockForUpdate` na linha da carteira. A composição fica na action de aplicação `ExecutarConsulta` (`app/`): reserva antes de executar, confirma no `success`, libera em `no_data`/`failed`. Um comando `wallet:expire-holds` solta holds órfãos.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, o sistema de módulos do projeto, Postgres com `SELECT ... FOR UPDATE`, casts de enum.

## Global Constraints

- PHP 8.4; `declare(strict_types=1)` no topo de todo arquivo PHP.
- Prosa e comentários `//`/`/* */` em **português**; docblocks `/** */` em **inglês** (o Peck roda no CI e não fala português).
- Comentário explica o **porquê**, nunca reescreve a linha seguinte.
- Constructor property promotion; tipos explícitos em todo parâmetro e retorno; PHPDoc com array shapes.
- Um módulo depende de `Core` e de si mesmo, **nunca de um irmão**. A composição mora em `app/`. O módulo `Carteira` **não** importa `Modules\Consultas\*`; a ligação com uma execução é por relação polimórfica (`morphTo`), que guarda a string da classe em runtime sem `use`.
- Todo model em `modules/*/Models` é escopado por `BelongsToTenant` (Wallet, WalletTransaction, WalletHold são todos por-time). `team_id` **não** é fillable — a trait o preenche a partir do tenant atual; por isso as actions rodam dentro de `TenantManager::forTenant($team, ...)`.
- Model tem menos de 150 linhas e não concentra regra de negócio (regra vai para Action).
- Dinheiro é **inteiro em centavos**. Saldo nunca fica negativo — a reserva é o que garante.
- Nada de `dd`/`dump`/`env()` fora de `config/`.
- **Trabalhar na branch `main`** (decisão do usuário: sem novas branches). Commite direto em `main`.
- **Testes rodam via Sail** (host não tem driver de banco). Comando de arquivo/filtro:
  `./vendor/bin/sail artisan test --compact <path>`. Suíte inteira:
  `./vendor/bin/sail artisan test --exclude-testsuite=Browser`. **Não** rode `./vendor/bin/sail up` (containers já no ar). **Não** use `composer test -- <path>`.
- `composer check` roda partido: **PHPStan e a suíte no Sail** (`./vendor/bin/sail composer types:check`), **Pint/Rector/Peck/type-coverage no host**.
- Depois de mexer em PHP: `vendor/bin/pint --dirty --format agent` (host).
- **Todo teste novo precisa ser visto falhar antes de valer.**
- Mensagem de commit em português, terminando com:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

## File Structure

```
modules/Carteira/
├── Providers/CarteiraServiceProvider.php
├── Enums/WalletTransactionType.php     # Recharge|Charge|Refund|Bonus|Adjustment
├── Enums/WalletHoldStatus.php          # Active|Settled|Released
├── Models/Wallet.php                   # 1 por time; saldo; available()
├── Models/WalletTransaction.php        # razão append-only; morphTo reference
├── Models/WalletHold.php               # reserva
├── Actions/
│   ├── CreditWallet.php                # recharge/bonus/adjustment (credita)
│   ├── ReserveCredit.php               # cria hold; lança InsufficientBalanceException
│   ├── ConfirmHold.php                 # hold -> charge; debita
│   └── ReleaseHold.php                 # solta hold
├── Console/Commands/ExpireHolds.php    # wallet:expire-holds
├── Exceptions/
│   ├── InsufficientBalanceException.php
│   └── HoldNotActiveException.php
├── Database/Factories/{Wallet,WalletTransaction,WalletHold}Factory.php
├── database/migrations/*               # wallets, wallet_transactions, wallet_holds
├── routes/console.php                  # agenda wallet:expire-holds
└── tests/Feature/*

modules/Consultas/
├── database/migrations/*_add_price_cents_to_consultas_table.php   # coluna de preço
├── Models/Consulta.php                 # +price_cents (fillable + @property)
└── Database/Factories/ConsultaFactory.php   # +price_cents default 0, state priced()

app/Actions/Consultas/ExecutarConsulta.php   # fia reserva/confirma/libera
composer.json                                # +classes Carteira no test:mutate
```

---

### Task 1: Scaffold do módulo Carteira

**Files:**
- Create: `modules/Carteira/` (via `php artisan make:module Carteira`)
- Modify: `config/modules.php` (o comando faz)

**Interfaces:**
- Produces: namespace `Modules\Carteira\` autoloadável, `CarteiraServiceProvider` registrado.

- [ ] **Step 1: Criar o módulo**

Run:
```bash
php artisan make:module Carteira
composer dump-autoload
```
Expected: cria `modules/Carteira/...`, adiciona `'Carteira'` em `config/modules.php`.

- [ ] **Step 2: Rodar a suíte de arquitetura (módulo vazio passa)**

Run: `./vendor/bin/sail artisan test --compact tests/Arch/ArchTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add modules/Carteira config/modules.php
git commit -m "feat(carteira): scaffold do módulo"
```

---

### Task 2: Enums WalletTransactionType e WalletHoldStatus

**Files:**
- Create: `modules/Carteira/Enums/WalletTransactionType.php`
- Create: `modules/Carteira/Enums/WalletHoldStatus.php`
- Test: `modules/Carteira/tests/Unit/WalletEnumsTest.php`

**Interfaces:**
- Produces:
  - `enum WalletTransactionType: string { Recharge='recharge'; Charge='charge'; Refund='refund'; Bonus='bonus'; Adjustment='adjustment'; }` + `isCredit(): bool` (true para Recharge/Bonus/Adjustment).
  - `enum WalletHoldStatus: string { Active='active'; Settled='settled'; Released='released'; }`

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Enums\WalletTransactionType;

it('classifica os tipos que creditam a carteira', function (): void {
    expect(WalletTransactionType::Recharge->isCredit())->toBeTrue()
        ->and(WalletTransactionType::Bonus->isCredit())->toBeTrue()
        ->and(WalletTransactionType::Adjustment->isCredit())->toBeTrue()
        ->and(WalletTransactionType::Charge->isCredit())->toBeFalse();
});

it('tem os estados de hold esperados', function (): void {
    expect(WalletHoldStatus::Active->value)->toBe('active')
        ->and(WalletHoldStatus::Settled->value)->toBe('settled')
        ->and(WalletHoldStatus::Released->value)->toBe('released');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Unit/WalletEnumsTest.php`
Expected: FAIL (enums inexistentes).

- [ ] **Step 3: Implementar os enums**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Enums;

/**
 * How a ledger line moved money.
 *
 * isCredit() marks the types that add to the balance; a Charge subtracts. The
 * distinction is what keeps a recharge from being written with a negative
 * amount, or a charge with a positive one.
 */
enum WalletTransactionType: string
{
    case Recharge = 'recharge';
    case Charge = 'charge';
    case Refund = 'refund';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';

    public function isCredit(): bool
    {
        return match ($this) {
            self::Recharge, self::Bonus, self::Adjustment, self::Refund => true,
            self::Charge => false,
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Enums;

/**
 * The life of a reservation: held, then either settled into a charge or
 * released back to the available balance.
 */
enum WalletHoldStatus: string
{
    case Active = 'active';
    case Settled = 'settled';
    case Released = 'released';
}
```

- [ ] **Step 4: Rodar e ver passar; formatar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Unit/WalletEnumsTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/Carteira
git commit -m "feat(carteira): enums de tipo de transação e estado de hold"
```

---

### Task 3: Model Wallet + migration + factory

**Files:**
- Create: `modules/Carteira/Models/Wallet.php`
- Create: `modules/Carteira/Database/Factories/WalletFactory.php`
- Create: `modules/Carteira/database/migrations/*_create_wallets_table.php`
- Test: `modules/Carteira/tests/Feature/WalletTest.php`

**Interfaces:**
- Produces:
  - `Wallet` (scoped por time): colunas `id, team_id (unique), balance_cents, timestamps`. `$fillable = ['balance_cents']`. Relations `transactions(): HasMany`, `holds(): HasMany`.
  - `Wallet->available(): int` = `balance_cents - soma dos holds ativos`.
  - `Wallet::forTeam(Team $team): Wallet` — firstOrCreate da carteira do time, resolvido com `withoutTenancy` + filtro explícito por `team_id` para funcionar de qualquer contexto (job, admin).

Nota: `holds()`/`transactions()` referenciam `WalletHold`/`WalletTransaction` que ainda não existem nas Tasks 3. Crie os stubs mínimos? Não — a Task 3 cria só `Wallet`; as relations são adicionadas quando os models existirem. Para manter a Task 3 testável isolada, `available()` e as relations que dependem de `WalletHold` entram na **Task 6** (depois que WalletHold existe). A Task 3 entrega `Wallet` com saldo, `forTeam()`, e `transactions()` fica para a Task 5. **Nesta task, o model tem apenas `balance_cents`, `team()` (da trait) e `forTeam()`.**

- [ ] **Step 1: Escrever a migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            // Uma carteira por time — o unique é o que garante isso mesmo sob
            // criação concorrente, quando o firstOrCreate perde a corrida.
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
```

- [ ] **Step 2: Escrever o factory**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Carteira\Models\Wallet;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'balance_cents' => 0,
        ];
    }

    public function withBalance(int $cents): self
    {
        return $this->state(['balance_cents' => $cents]);
    }
}
```

- [ ] **Step 3: Escrever o model**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Models;

use App\Concerns\BelongsToTenant;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Carteira\Database\Factories\WalletFactory;

/**
 * A team's balance, in cents.
 *
 * One row per team (enforced by a unique index), created lazily the first time
 * money moves. Scoped by team like every domain model; forTeam() resolves it
 * from any context — a queued job or the operator panel — by filtering on the
 * explicit team_id with tenancy suspended.
 *
 * @property int $id
 * @property int $team_id
 * @property int $balance_cents
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable(['balance_cents'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use BelongsToTenant, HasFactory;

    public static function forTeam(Team $team): self
    {
        return self::withoutTenancy(
            fn (): self => self::query()->where('team_id', $team->getKey())->first()
                ?? self::withoutEvents(fn (): self => self::query()->create(['team_id' => $team->getKey()])),
        );
    }
}
```

Nota sobre `create(['team_id' => ...])`: `team_id` não é fillable, então passe-o via `forceFill` para driblar o mass-assignment sem torná-lo fillable. Ajuste o `create` acima para:

```php
                ?? self::withoutEvents(function () use ($team): self {
                    $wallet = new self;
                    $wallet->forceFill(['team_id' => $team->getKey(), 'balance_cents' => 0]);
                    $wallet->save();

                    return $wallet;
                }),
```

`withoutEvents` evita o hook `creating` da trait (que preencheria `team_id` do tenant atual, errado sob `withoutTenancy`).

- [ ] **Step 4: Escrever o teste**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Modules\Carteira\Models\Wallet;

it('cria a carteira do time uma vez e reusa depois', function (): void {
    $team = Team::factory()->create();

    $first = Wallet::forTeam($team);
    $second = Wallet::forTeam($team);

    expect($first->id)->toBe($second->id)
        ->and($first->team_id)->toBe($team->id)
        ->and($first->balance_cents)->toBe(0)
        ->and(Wallet::withoutTenancy(fn () => Wallet::query()->where('team_id', $team->id)->count()))->toBe(1);
});

it('resolve a carteira sem tenant no contexto', function (): void {
    $team = Team::factory()->create();

    // Nenhum tenant setado — forTeam precisa funcionar mesmo assim (job/admin).
    $wallet = Wallet::forTeam($team);

    expect($wallet->team_id)->toBe($team->id);
});
```

- [ ] **Step 5: Rodar e ver passar; formatar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/WalletTest.php tests/Arch/ArchTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS (a Arch confirma que o model escopado usa a trait).

- [ ] **Step 6: Commit**

```bash
git add modules/Carteira
git commit -m "feat(carteira): model Wallet com saldo em centavos e forTeam"
```

---

### Task 4: Model WalletTransaction (razão append-only)

**Files:**
- Create: `modules/Carteira/Models/WalletTransaction.php`
- Create: `modules/Carteira/Database/Factories/WalletTransactionFactory.php`
- Create: `modules/Carteira/database/migrations/*_create_wallet_transactions_table.php`
- Modify: `modules/Carteira/Models/Wallet.php` (adiciona `transactions(): HasMany`)
- Test: `modules/Carteira/tests/Feature/WalletTransactionTest.php`

**Interfaces:**
- Consumes: `Wallet` (Task 3), `WalletTransactionType` (Task 2).
- Produces:
  - `WalletTransaction` (scoped): `id, team_id, wallet_id, type (enum), amount_cents (signed), balance_after_cents, description, reference_type (nullable), reference_id (nullable), timestamps`. Casts `type => WalletTransactionType::class`. `reference(): MorphTo`. `$fillable = ['wallet_id','type','amount_cents','balance_after_cents','description','reference_type','reference_id']`.
  - `Wallet->transactions(): HasMany<WalletTransaction>`.

- [ ] **Step 1: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            // Assinado: crédito é positivo, débito (charge) é negativo.
            $table->bigInteger('amount_cents');
            // O saldo depois desta linha. O razão é a fonte da verdade; a coluna
            // em wallets é cache reconciliável contra a soma daqui.
            $table->bigInteger('balance_after_cents');
            $table->string('description');
            // Referência polimórfica sem FK: liga a linha à execução (ou, depois,
            // ao pagamento) sem o módulo Carteira depender do módulo Consultas.
            $table->nullableMorphs('reference');
            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
```

- [ ] **Step 2: Factory**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletTransaction;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'wallet_id' => Wallet::factory(),
            'type' => WalletTransactionType::Recharge,
            'amount_cents' => 1000,
            'balance_after_cents' => 1000,
            'description' => 'Recarga de teste',
            'reference_type' => null,
            'reference_id' => null,
        ];
    }
}
```

- [ ] **Step 3: Model**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Carteira\Database\Factories\WalletTransactionFactory;
use Modules\Carteira\Enums\WalletTransactionType;
use Override;

/**
 * One immutable line in a wallet's ledger.
 *
 * Never updated after it is written — a correction is another line, not an edit.
 * balance_after_cents freezes the running balance at the moment it was written,
 * so the ledger reconciles against the wallet's cached balance.
 *
 * @property int $id
 * @property int $team_id
 * @property int $wallet_id
 * @property WalletTransactionType $type
 * @property int $amount_cents
 * @property int $balance_after_cents
 * @property string $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable(['wallet_id', 'type', 'amount_cents', 'balance_after_cents', 'description', 'reference_type', 'reference_id'])]
class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
        ];
    }
}
```

Adicione ao `Wallet` (Task 3):

```php
    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<WalletTransaction, $this>
     */
    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
```

- [ ] **Step 4: Teste**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletTransaction;

it('grava uma linha de razão ligada à carteira', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    $wallet = Wallet::forTeam($team);
    $tx = WalletTransaction::factory()->create([
        'team_id' => $team->id,
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Recharge,
        'amount_cents' => 5000,
        'balance_after_cents' => 5000,
    ]);

    expect($wallet->transactions()->count())->toBe(1)
        ->and($tx->type)->toBe(WalletTransactionType::Recharge);
});

it('é escopado por time', function (): void {
    $tenants = resolve(TenantManager::class);
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $tenants->set($teamA);
    WalletTransaction::factory()->create(['team_id' => $teamA->id, 'wallet_id' => Wallet::forTeam($teamA)->id]);

    $tenants->set($teamB);

    expect(WalletTransaction::query()->count())->toBe(0);
});
```

- [ ] **Step 5: Rodar e ver passar; formatar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/WalletTransactionTest.php tests/Arch/ArchTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/Carteira
git commit -m "feat(carteira): razão append-only WalletTransaction"
```

---

### Task 5: Model WalletHold + Wallet::available()

**Files:**
- Create: `modules/Carteira/Models/WalletHold.php`
- Create: `modules/Carteira/Database/Factories/WalletHoldFactory.php`
- Create: `modules/Carteira/database/migrations/*_create_wallet_holds_table.php`
- Modify: `modules/Carteira/Models/Wallet.php` (adiciona `holds(): HasMany` e `available(): int`)
- Test: `modules/Carteira/tests/Feature/WalletAvailableTest.php`

**Interfaces:**
- Consumes: `Wallet` (Task 3), `WalletHoldStatus` (Task 2).
- Produces:
  - `WalletHold` (scoped): `id, team_id, wallet_id, amount_cents, status (enum, default active), expires_at, settled_at (nullable), released_at (nullable), timestamps`. Casts `status => WalletHoldStatus::class`, `expires_at/settled_at/released_at => 'datetime'`. `$fillable = ['wallet_id','amount_cents','status','expires_at','settled_at','released_at']`.
  - `Wallet->holds(): HasMany<WalletHold>`.
  - `Wallet->available(): int` = `balance_cents - soma amount_cents dos holds com status Active`.

- [ ] **Step 1: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('status')->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            // O expurgo varre holds ativos vencidos; o disponível soma holds ativos.
            $table->index(['status', 'expires_at']);
            $table->index('wallet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_holds');
    }
};
```

- [ ] **Step 2: Factory**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;

/**
 * @extends Factory<WalletHold>
 */
class WalletHoldFactory extends Factory
{
    protected $model = WalletHold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'wallet_id' => Wallet::factory(),
            'amount_cents' => 350,
            'status' => WalletHoldStatus::Active,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
```

- [ ] **Step 3: Model**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Carteira\Database\Factories\WalletHoldFactory;
use Modules\Carteira\Enums\WalletHoldStatus;
use Override;

/**
 * Money set aside for an in-flight operation.
 *
 * A hold reduces the available balance without moving it: the charge only lands
 * if the work succeeds (settled), otherwise the hold is released. It exists so
 * two concurrent operations cannot both spend the same balance while neither
 * has settled yet. An orphaned hold is swept by wallet:expire-holds.
 *
 * @property int $id
 * @property int $team_id
 * @property int $wallet_id
 * @property int $amount_cents
 * @property WalletHoldStatus $status
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $settled_at
 * @property \Illuminate\Support\Carbon|null $released_at
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable(['wallet_id', 'amount_cents', 'status', 'expires_at', 'settled_at', 'released_at'])]
class WalletHold extends Model
{
    /** @use HasFactory<WalletHoldFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => WalletHoldStatus::class,
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
```

Adicione ao `Wallet`:

```php
    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<WalletHold, $this>
     */
    public function holds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WalletHold::class);
    }

    /**
     * Balance minus everything currently reserved.
     */
    public function available(): int
    {
        $reserved = (int) $this->holds()
            ->where('status', \Modules\Carteira\Enums\WalletHoldStatus::Active->value)
            ->sum('amount_cents');

        return $this->balance_cents - $reserved;
    }
```

- [ ] **Step 4: Teste**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;

it('desconta holds ativos do saldo disponível', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    $wallet = Wallet::forTeam($team);
    $wallet->forceFill(['balance_cents' => 1000])->save();

    WalletHold::factory()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id, 'amount_cents' => 300]);

    expect($wallet->fresh()->available())->toBe(700)
        ->and($wallet->fresh()->balance_cents)->toBe(1000);
});

it('holds soltos ou liquidados não reduzem o disponível', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    $wallet = Wallet::forTeam($team);
    $wallet->forceFill(['balance_cents' => 1000])->save();

    WalletHold::factory()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id, 'amount_cents' => 300, 'status' => \Modules\Carteira\Enums\WalletHoldStatus::Released]);

    expect($wallet->fresh()->available())->toBe(1000);
});
```

- [ ] **Step 5: Rodar e ver passar; formatar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/WalletAvailableTest.php tests/Arch/ArchTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/Carteira
git commit -m "feat(carteira): WalletHold e saldo disponível"
```

---

### Task 6: Action CreditWallet

**Files:**
- Create: `modules/Carteira/Actions/CreditWallet.php`
- Test: `modules/Carteira/tests/Feature/CreditWalletTest.php`

**Interfaces:**
- Consumes: `Wallet`, `WalletTransaction`, `WalletTransactionType`, `App\Support\TenantManager`.
- Produces:
  - `CreditWallet->handle(Team $team, int $amountCents, WalletTransactionType $type, string $description, ?Model $reference = null): WalletTransaction`
  - Requisito: `$amountCents > 0` e `$type->isCredit()`; senão `InvalidArgumentException`. Roda em `forTenant($team, ...)` + `DB::transaction` com a carteira travada (`lockForUpdate`); grava a linha e atualiza `balance_cents`; `balance_after_cents` = novo saldo.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;

it('credita a carteira e grava a linha de razão', function (): void {
    $team = Team::factory()->create();

    $tx = resolve(CreditWallet::class)->handle($team, 5000, WalletTransactionType::Recharge, 'Recarga PIX');

    expect($tx->amount_cents)->toBe(5000)
        ->and($tx->balance_after_cents)->toBe(5000)
        ->and($tx->type)->toBe(WalletTransactionType::Recharge)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(5000);
});

it('soma créditos sucessivos no balance_after', function (): void {
    $team = Team::factory()->create();
    $credit = resolve(CreditWallet::class);

    $credit->handle($team, 5000, WalletTransactionType::Recharge, 'Recarga');
    $second = $credit->handle($team, 2000, WalletTransactionType::Bonus, 'Bônus');

    expect($second->balance_after_cents)->toBe(7000)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(7000);
});

it('recusa valor não positivo', function (): void {
    $team = Team::factory()->create();

    resolve(CreditWallet::class)->handle($team, 0, WalletTransactionType::Recharge, 'Zero');
})->throws(InvalidArgumentException::class);

it('recusa tipo que não credita', function (): void {
    $team = Team::factory()->create();

    resolve(CreditWallet::class)->handle($team, 100, WalletTransactionType::Charge, 'Débito indevido');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/CreditWalletTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Actions;

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletTransaction;

/**
 * Adds money to a team's wallet — a recharge, a bonus, or an operator
 * adjustment — and writes the ledger line.
 *
 * Runs inside the team's tenant context so the scoped models resolve to the
 * right team, and locks the wallet row so a concurrent credit or charge reads
 * the balance this one is about to change, not a stale copy.
 */
class CreditWallet
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Team $team, int $amountCents, WalletTransactionType $type, string $description, ?Model $reference = null): WalletTransaction
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Crédito precisa de valor positivo.');
        }

        if (! $type->isCredit()) {
            throw new InvalidArgumentException("O tipo [{$type->value}] não credita a carteira.");
        }

        return $this->tenants->forTenant($team, fn (): WalletTransaction => DB::transaction(function () use ($team, $amountCents, $type, $description, $reference): WalletTransaction {
            $wallet = Wallet::forTeam($team);
            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();

            $newBalance = $wallet->balance_cents + $amountCents;

            $transaction = $wallet->transactions()->create([
                'type' => $type,
                'amount_cents' => $amountCents,
                'balance_after_cents' => $newBalance,
                'description' => $description,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);

            $wallet->forceFill(['balance_cents' => $newBalance])->save();

            return $transaction;
        }));
    }
}
```

Nota: `$wallet->transactions()->create([...])` preenche `wallet_id` pela relação e `team_id` pela trait (estamos em `forTenant`). Se o PHPStan reclamar que `lockForUpdate()->first()` pode ser `null`, encadeie `->firstOrFail()` — a carteira acabou de ser garantida por `forTeam`.

- [ ] **Step 4: Rodar e ver passar; formatar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/CreditWalletTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/Carteira
git commit -m "feat(carteira): action CreditWallet"
```

---

### Task 7: Action ReserveCredit + InsufficientBalanceException

**Files:**
- Create: `modules/Carteira/Actions/ReserveCredit.php`
- Create: `modules/Carteira/Exceptions/InsufficientBalanceException.php`
- Test: `modules/Carteira/tests/Feature/ReserveCreditTest.php`

**Interfaces:**
- Consumes: `Wallet`, `WalletHold`, `WalletHoldStatus`, `TenantManager`, `CreditWallet` (nos testes, para fundear).
- Produces:
  - `const int ReserveCredit::HOLD_TTL_MINUTES = 15;`
  - `ReserveCredit->handle(Team $team, int $amountCents): WalletHold` — trava a carteira, calcula `available` sob o lock, e se `available >= amountCents` cria hold Active com `expires_at = now()+HOLD_TTL_MINUTES`; senão lança `InsufficientBalanceException`.
  - `InsufficientBalanceException::because(int $available, int $requested): self`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Actions\ReserveCredit;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Exceptions\InsufficientBalanceException;
use Modules\Carteira\Models\Wallet;

function fund(Team $team, int $cents): void
{
    resolve(CreditWallet::class)->handle($team, $cents, WalletTransactionType::Recharge, 'Fundo de teste');
}

it('reserva dentro do saldo e cria hold ativo', function (): void {
    $team = Team::factory()->create();
    fund($team, 1000);

    $hold = resolve(ReserveCredit::class)->handle($team, 350);

    expect($hold->amount_cents)->toBe(350)
        ->and($hold->status)->toBe(WalletHoldStatus::Active)
        ->and($hold->expires_at->isFuture())->toBeTrue()
        ->and(Wallet::forTeam($team)->available())->toBe(650)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(1000);
});

it('recusa reservar além do disponível', function (): void {
    $team = Team::factory()->create();
    fund($team, 300);

    resolve(ReserveCredit::class)->handle($team, 350);
})->throws(InsufficientBalanceException::class);

it('duas reservas não gastam o mesmo saldo (anti double-spend)', function (): void {
    $team = Team::factory()->create();
    fund($team, 500);
    $reserve = resolve(ReserveCredit::class);

    $reserve->handle($team, 300); // disponível cai para 200

    // a segunda pede 300, só há 200 disponível
    expect(fn () => $reserve->handle($team, 300))->toThrow(InsufficientBalanceException::class);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/ReserveCreditTest.php`
Expected: FAIL.

- [ ] **Step 3: Exceção**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Exceptions;

use RuntimeException;

final class InsufficientBalanceException extends RuntimeException
{
    public static function because(int $available, int $requested): self
    {
        return new self("Saldo insuficiente: disponível {$available}, pedido {$requested} (em centavos).");
    }
}
```

- [ ] **Step 4: Action**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Actions;

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Facades\DB;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Exceptions\InsufficientBalanceException;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;

/**
 * Reserves money for an in-flight operation, or refuses when there is not
 * enough available.
 *
 * The wallet row is locked for the whole check-then-hold, so two operations
 * racing for the last of the balance cannot both pass the check: the second
 * waits for the first's transaction to commit, then sees the reduced available
 * and is refused. Without the lock, both would read the old balance and both
 * would hold — the double-spend this exists to prevent.
 */
class ReserveCredit
{
    /**
     * Quanto tempo um hold sobrevive sem ser confirmado ou solto, antes do
     * expurgo recolhê-lo. Curto porque a execução é síncrona; o hold só dura
     * mais que isso se o processo morreu no meio.
     */
    public const int HOLD_TTL_MINUTES = 15;

    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Team $team, int $amountCents): WalletHold
    {
        return $this->tenants->forTenant($team, fn (): WalletHold => DB::transaction(function () use ($team, $amountCents): WalletHold {
            $wallet = Wallet::forTeam($team);
            // Trava a linha antes de ler o disponível: o lock segura qualquer
            // outra reserva do mesmo time até esta transação commitar.
            Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();

            $available = $wallet->fresh()->available();

            if ($available < $amountCents) {
                throw InsufficientBalanceException::because($available, $amountCents);
            }

            return $wallet->holds()->create([
                'amount_cents' => $amountCents,
                'status' => WalletHoldStatus::Active,
                'expires_at' => now()->addMinutes(self::HOLD_TTL_MINUTES),
            ]);
        }));
    }
}
```

Nota: `$wallet->fresh()->available()` recarrega dentro da transação travada, somando os holds ativos já existentes. O lock em `wallets` serializa as reservas concorrentes do mesmo time.

- [ ] **Step 5: Rodar e ver passar; formatar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/ReserveCreditTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/Carteira
git commit -m "feat(carteira): reserva de crédito com lock e recusa por saldo"
```

---

### Task 8: Actions ConfirmHold e ReleaseHold + HoldNotActiveException

**Files:**
- Create: `modules/Carteira/Actions/ConfirmHold.php`
- Create: `modules/Carteira/Actions/ReleaseHold.php`
- Create: `modules/Carteira/Exceptions/HoldNotActiveException.php`
- Test: `modules/Carteira/tests/Feature/SettleHoldTest.php`

**Interfaces:**
- Consumes: `WalletHold`, `Wallet`, `WalletTransaction`, `WalletTransactionType`, `WalletHoldStatus`, `TenantManager`, `ReserveCredit`/`CreditWallet` (testes).
- Produces:
  - `ConfirmHold->handle(WalletHold $hold, string $description, ?Model $reference = null): WalletTransaction` — hold Active vira `Charge` (amount negativo), debita a carteira, marca hold `Settled`+`settled_at`. Hold não-Active → `HoldNotActiveException`.
  - `ReleaseHold->handle(WalletHold $hold): void` — hold Active vira `Released`+`released_at`; hold já Released é no-op idempotente; hold Settled → `HoldNotActiveException`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Modules\Carteira\Actions\ConfirmHold;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Actions\ReleaseHold;
use Modules\Carteira\Actions\ReserveCredit;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Exceptions\HoldNotActiveException;
use Modules\Carteira\Models\Wallet;

function funded(int $cents): Team
{
    $team = Team::factory()->create();
    resolve(CreditWallet::class)->handle($team, $cents, WalletTransactionType::Recharge, 'Fundo');

    return $team;
}

it('confirmar um hold debita a carteira e grava a cobrança', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);

    $tx = resolve(ConfirmHold::class)->handle($hold, 'Consulta X');

    expect($tx->type)->toBe(WalletTransactionType::Charge)
        ->and($tx->amount_cents)->toBe(-350)
        ->and($tx->balance_after_cents)->toBe(650)
        ->and($hold->fresh()->status)->toBe(WalletHoldStatus::Settled)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(650)
        ->and(Wallet::forTeam($team)->available())->toBe(650);
});

it('soltar um hold não debita e devolve o disponível', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);

    resolve(ReleaseHold::class)->handle($hold);

    expect($hold->fresh()->status)->toBe(WalletHoldStatus::Released)
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(1000)
        ->and(Wallet::forTeam($team)->available())->toBe(1000);
});

it('soltar um hold já solto é no-op', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);
    $release = resolve(ReleaseHold::class);

    $release->handle($hold);
    $release->handle($hold->fresh());

    expect($hold->fresh()->status)->toBe(WalletHoldStatus::Released);
});

it('não confirma um hold já solto', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);
    resolve(ReleaseHold::class)->handle($hold);

    resolve(ConfirmHold::class)->handle($hold->fresh(), 'Tarde demais');
})->throws(HoldNotActiveException::class);

it('não solta um hold já confirmado', function (): void {
    $team = funded(1000);
    $hold = resolve(ReserveCredit::class)->handle($team, 350);
    resolve(ConfirmHold::class)->handle($hold, 'Consulta X');

    resolve(ReleaseHold::class)->handle($hold->fresh());
})->throws(HoldNotActiveException::class);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/SettleHoldTest.php`
Expected: FAIL.

- [ ] **Step 3: Exceção**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Exceptions;

use RuntimeException;

final class HoldNotActiveException extends RuntimeException
{
    public static function forId(int $id): self
    {
        return new self("O hold [{$id}] não está ativo.");
    }
}
```

- [ ] **Step 4: ConfirmHold**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Exceptions\HoldNotActiveException;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;
use Modules\Carteira\Models\WalletTransaction;

/**
 * Settles a hold into a charge: the reserved money finally moves.
 *
 * Locks both the hold and the wallet so a confirm cannot race a release of the
 * same hold, nor read a balance another charge is mid-write on. A hold that is
 * no longer Active — already released or already settled — is refused rather
 * than double-charged.
 */
class ConfirmHold
{
    public function handle(WalletHold $hold, string $description, ?Model $reference = null): WalletTransaction
    {
        return $hold->team->run(fn (): WalletTransaction => DB::transaction(function () use ($hold, $description, $reference): WalletTransaction {
            $locked = WalletHold::query()->whereKey($hold->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== WalletHoldStatus::Active) {
                throw HoldNotActiveException::forId($locked->getKey());
            }

            $wallet = Wallet::query()->whereKey($locked->wallet_id)->lockForUpdate()->firstOrFail();
            $newBalance = $wallet->balance_cents - $locked->amount_cents;

            $transaction = $wallet->transactions()->create([
                'type' => WalletTransactionType::Charge,
                'amount_cents' => -$locked->amount_cents,
                'balance_after_cents' => $newBalance,
                'description' => $description,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);

            $wallet->forceFill(['balance_cents' => $newBalance])->save();
            $locked->forceFill(['status' => WalletHoldStatus::Settled, 'settled_at' => now()])->save();

            return $transaction;
        }));
    }
}
```

**Importante:** `$hold->team->run(...)` acima é um atalho fictício. Use `resolve(TenantManager::class)->forTenant($hold->team, ...)`. Injete `TenantManager $tenants` no construtor e chame `$this->tenants->forTenant($hold->team, ...)`. Corrija ConfirmHold e ReleaseHold para receber `TenantManager` no construtor (property promotion) e usar `$this->tenants->forTenant($hold->team, fn () => ...)`.

Versão correta do construtor e do wrap (aplique nas duas actions):

```php
    public function __construct(private readonly \App\Support\TenantManager $tenants) {}

    // ... dentro do handle:
    return $this->tenants->forTenant($hold->team, fn (): WalletTransaction => DB::transaction(function () use (...) { ... }));
```

- [ ] **Step 5: ReleaseHold**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Actions;

use App\Support\TenantManager;
use Illuminate\Support\Facades\DB;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Exceptions\HoldNotActiveException;
use Modules\Carteira\Models\WalletHold;

/**
 * Releases a hold back to the available balance without moving any money.
 *
 * Idempotent for an already-released hold — the expiry sweep and the caller's
 * failure path can both fire on the same hold, and neither should error. A
 * settled hold cannot be released: the money already moved.
 */
class ReleaseHold
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(WalletHold $hold): void
    {
        $this->tenants->forTenant($hold->team, function () use ($hold): void {
            DB::transaction(function () use ($hold): void {
                $locked = WalletHold::query()->whereKey($hold->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status === WalletHoldStatus::Released) {
                    return;
                }

                if ($locked->status !== WalletHoldStatus::Active) {
                    throw HoldNotActiveException::forId($locked->getKey());
                }

                $locked->forceFill(['status' => WalletHoldStatus::Released, 'released_at' => now()])->save();
            });
        });
    }
}
```

- [ ] **Step 6: Rodar e ver passar; formatar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/SettleHoldTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add modules/Carteira
git commit -m "feat(carteira): confirmar e soltar hold com guarda de estado"
```

---

### Task 9: Comando wallet:expire-holds

**Files:**
- Create: `modules/Carteira/Console/Commands/ExpireHolds.php`
- Create: `modules/Carteira/routes/console.php` (agenda o comando)
- Test: `modules/Carteira/tests/Feature/ExpireHoldsTest.php`

**Interfaces:**
- Consumes: `WalletHold`, `WalletHoldStatus`.
- Produces: comando `wallet:expire-holds` que solta (status Released, `released_at=now`) todo hold **Active** com `expires_at < now`, em todos os times (`acrossTenants`). Agendado a cada minuto.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;

it('solta holds ativos vencidos e mantém os recentes', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $wallet = Wallet::forTeam($team);

    $expired = WalletHold::factory()->expired()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id]);
    $fresh = WalletHold::factory()->create(['team_id' => $team->id, 'wallet_id' => $wallet->id]);

    $this->artisan('wallet:expire-holds')->assertExitCode(0);

    expect($expired->fresh()->status)->toBe(WalletHoldStatus::Released)
        ->and($expired->fresh()->released_at)->not->toBeNull()
        ->and($fresh->fresh()->status)->toBe(WalletHoldStatus::Active);
});

it('não toca em hold já liquidado mesmo vencido', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $wallet = Wallet::forTeam($team);

    $settled = WalletHold::factory()->expired()->create([
        'team_id' => $team->id, 'wallet_id' => $wallet->id, 'status' => WalletHoldStatus::Settled,
    ]);

    $this->artisan('wallet:expire-holds')->assertExitCode(0);

    expect($settled->fresh()->status)->toBe(WalletHoldStatus::Settled);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/ExpireHoldsTest.php`
Expected: FAIL (comando inexistente).

- [ ] **Step 3: Comando**

```php
<?php

declare(strict_types=1);

namespace Modules\Carteira\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Models\WalletHold;

/**
 * Releases holds that were never settled or released in time.
 *
 * A hold outlives its window only when the process that made it died between
 * reserving and settling. Sweeping them frees the reserved balance that would
 * otherwise sit locked forever. Runs across every team.
 */
#[Signature('wallet:expire-holds')]
#[Description('Solta holds ativos que passaram do prazo, em todos os times')]
class ExpireHolds extends Command
{
    public function handle(): int
    {
        $released = WalletHold::acrossTenants()
            ->where('status', WalletHoldStatus::Active->value)
            ->where('expires_at', '<', now())
            ->update([
                'status' => WalletHoldStatus::Released->value,
                'released_at' => now(),
            ]);

        $this->components->info("Holds soltos: {$released}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Agendar (routes/console.php do módulo)**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Modules\Carteira\Console\Commands\ExpireHolds;

// Hold órfão prende saldo; varrer de minuto em minuto mantém a janela curta.
Schedule::command(ExpireHolds::class)->everyMinute()->withoutOverlapping();
```

Se o comando não for descoberto automaticamente, registre-o no `CarteiraServiceProvider` (Task 11) via `$this->commands([ExpireHolds::class])` dentro de `bootModule()`. Confirme com `./vendor/bin/sail artisan list | grep wallet:expire-holds`.

- [ ] **Step 5: Rodar e ver passar; formatar**

Run: `./vendor/bin/sail artisan test --compact modules/Carteira/tests/Feature/ExpireHoldsTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/Carteira
git commit -m "feat(carteira): expurgo de holds vencidos"
```

---

### Task 10: Preço na consulta e fiação da cobrança em ExecutarConsulta

**Files:**
- Create: `modules/Consultas/database/migrations/*_add_price_cents_to_consultas_table.php`
- Modify: `modules/Consultas/Models/Consulta.php` (`@property int $price_cents` + fillable)
- Modify: `modules/Consultas/Database/Factories/ConsultaFactory.php` (`price_cents` default 0 + state `priced()`)
- Modify: `app/Actions/Consultas/ExecutarConsulta.php` (fia reserva/confirma/libera)
- Modify: `tests/Feature/Consultas/ExecutarConsultaTest.php` (novos casos de cobrança; os 4 existentes seguem com preço 0)
- Test: `tests/Feature/Consultas/CobrancaConsultaTest.php` (novo, casos de cobrança)

**Interfaces:**
- Consumes: `ReserveCredit`, `ConfirmHold`, `ReleaseHold`, `CreditWallet` (testes), `TenantManager`, `Consulta`, `ConsultaExecution`, `ProviderResult`, `InsufficientBalanceException`.
- Produces:
  - `Consulta.price_cents` (int, default 0) — o preço em crédito da consulta.
  - `ExecutarConsulta->handle(...)` agora: reserva `price_cents` (se > 0) **antes** de executar; `success` → confirma o hold (Charge referenciando a execução); `no_data`/`failed`/exceção → solta o hold. Saldo insuficiente lança `InsufficientBalanceException` **antes** de chamar o provider.

- [ ] **Step 1: Migration da coluna de preço**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $table): void {
            // Preço em crédito, em centavos. Zero = sem cobrança por crédito
            // (consulta gratuita, ou coberta por plano quando a Fase D chegar).
            $table->unsignedInteger('price_cents')->default(0)->after('provider_key');
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $table): void {
            $table->dropColumn('price_cents');
        });
    }
};
```

- [ ] **Step 2: Atualizar model e factory da consulta**

No `modules/Consultas/Models/Consulta.php`: adicione `@property int $price_cents` ao docblock e inclua `'price_cents'` na lista do atributo `#[Fillable([...])]`.

No `modules/Consultas/Database/Factories/ConsultaFactory.php`: adicione `'price_cents' => 0` ao `definition()` e o state:

```php
    public function priced(int $cents): self
    {
        return $this->state(['price_cents' => $cents]);
    }
```

- [ ] **Step 3: Escrever os testes de cobrança que falham**

`tests/Feature/Consultas/CobrancaConsultaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Consultas\ExecutarConsulta;
use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Exceptions\InsufficientBalanceException;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletHold;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Models\ConsultaExecution;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\FakeProvider;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ProviderResult;

function setupTeam(int $balance): Team
{
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    resolve(CreditWallet::class)->handle($team, $balance, WalletTransactionType::Recharge, 'Fundo');

    return $team;
}

function input(): ConsultaInput
{
    return ConsultaInput::fromArray(['cpf' => '12345678901']);
}

it('sucesso debita o preço e liga a cobrança à execução', function (): void {
    $team = setupTeam(1000);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $consulta = Consulta::factory()->priced(350)->create(['provider_key' => 'fake']);

    $execution = resolve(ExecutarConsulta::class)->handle($consulta, input());

    expect(Wallet::forTeam($team)->balance_cents)->toBe(650)
        ->and(WalletHold::query()->where('status', WalletHoldStatus::Settled->value)->count())->toBe(1);

    $charge = $team->wallet_charge = \Modules\Carteira\Models\WalletTransaction::query()
        ->where('type', WalletTransactionType::Charge->value)->firstOrFail();
    expect($charge->reference_id)->toBe($execution->id)
        ->and($charge->reference_type)->toBe($execution->getMorphClass());
});

it('nada encontrado não cobra e solta o hold', function (): void {
    $team = setupTeam(1000);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::noData()));
    $consulta = Consulta::factory()->priced(350)->create(['provider_key' => 'fake']);

    resolve(ExecutarConsulta::class)->handle($consulta, input());

    expect(Wallet::forTeam($team)->balance_cents)->toBe(1000)
        ->and(Wallet::forTeam($team)->available())->toBe(1000)
        ->and(WalletHold::query()->where('status', WalletHoldStatus::Released->value)->count())->toBe(1);
});

it('falha técnica não cobra e solta o hold', function (): void {
    $team = setupTeam(1000);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::failed('timeout')));
    $consulta = Consulta::factory()->priced(350)->create(['provider_key' => 'fake']);

    resolve(ExecutarConsulta::class)->handle($consulta, input());

    expect(Wallet::forTeam($team)->balance_cents)->toBe(1000)
        ->and(Wallet::forTeam($team)->available())->toBe(1000);
});

it('saldo insuficiente recusa antes de executar e não registra execução', function (): void {
    $team = setupTeam(100); // menos que o preço
    $provider = FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']));
    resolve(ProviderRegistry::class)->register($provider);
    $consulta = Consulta::factory()->priced(350)->create(['provider_key' => 'fake']);

    expect(fn () => resolve(ExecutarConsulta::class)->handle($consulta, input()))
        ->toThrow(InsufficientBalanceException::class);

    expect(ConsultaExecution::query()->count())->toBe(0)
        ->and($provider->calls())->toBe(0);
});

it('consulta de preço zero não toca a carteira', function (): void {
    $team = setupTeam(0);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $consulta = Consulta::factory()->priced(0)->create(['provider_key' => 'fake']);

    $execution = resolve(ExecutarConsulta::class)->handle($consulta, input());

    expect($execution->outcome->value)->toBe('success')
        ->and(\Modules\Carteira\Models\WalletTransaction::query()->count())->toBe(0)
        ->and(WalletHold::query()->count())->toBe(0);
});
```

Nota: a linha `$team->wallet_charge = ...` no primeiro teste é só para evitar variável solta; se o Pint/PHPStan reclamar, use uma variável local `$charge = ...` direto. Prefira a variável local.

- [ ] **Step 4: Rodar e ver falhar**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Consultas/CobrancaConsultaTest.php`
Expected: FAIL (ExecutarConsulta ainda não cobra).

- [ ] **Step 5: Fiar a cobrança em ExecutarConsulta**

Substitua `app/Actions/Consultas/ExecutarConsulta.php` por:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Consultas;

use App\Exceptions\TenantIsMissingException;
use App\Models\User;
use App\Support\TenantManager;
use Modules\Carteira\Actions\ConfirmHold;
use Modules\Carteira\Actions\ReleaseHold;
use Modules\Carteira\Actions\ReserveCredit;
use Modules\Carteira\Models\WalletHold;
use Modules\Consultas\Exceptions\ConsultaUnavailableException;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Models\ConsultaExecution;
use Modules\Consultas\Support\CircuitBreaker;
use Modules\Consultas\Support\ConsultaAvailability;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ResilientProvider;
use Throwable;

/**
 * Runs one consulta, charges for it only when it returns useful data, and
 * records the outcome.
 *
 * The order is the point: reserve first, so an execution that cannot be paid
 * for never reaches the provider; then settle the reservation into a charge on
 * success, or release it on no_data / failure / any error. The reserve holds
 * the money across the slow provider call so two runs cannot spend the same
 * balance.
 */
class ExecutarConsulta
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ConsultaAvailability $availability,
        private readonly CircuitBreaker $breaker,
        private readonly TenantManager $tenants,
        private readonly ReserveCredit $reserveCredit,
        private readonly ConfirmHold $confirmHold,
        private readonly ReleaseHold $releaseHold,
    ) {}

    public function handle(Consulta $consulta, ConsultaInput $input, ?User $user = null): ConsultaExecution
    {
        if (! $this->availability->available($consulta)) {
            throw ConsultaUnavailableException::for($consulta);
        }

        $team = $this->tenants->current();

        if ($team === null) {
            throw TenantIsMissingException::forModel(ConsultaExecution::class);
        }

        $hold = $consulta->price_cents > 0
            ? $this->reserveCredit->handle($team, $consulta->price_cents)
            : null;

        try {
            $provider = $this->registry->resolve($consulta->provider_key);
            $resilient = new ResilientProvider($provider, $this->breaker);

            $startedAt = hrtime(true);
            $result = $resilient->execute($input);
            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

            $execution = ConsultaExecution::create([
                'consulta_id' => $consulta->id,
                'user_id' => $user?->id,
                'outcome' => $result->outcome(),
                'duration_ms' => $durationMs,
                'input_masked' => $input->masked(),
                'result' => $result->isSuccess() ? $result->data() : null,
                'failure_reason' => $result->reason(),
            ]);

            $this->settle($hold, $result->isSuccess(), $consulta, $execution);

            return $execution;
        } catch (Throwable $e) {
            $this->release($hold);

            throw $e;
        }
    }

    private function settle(?WalletHold $hold, bool $success, Consulta $consulta, ConsultaExecution $execution): void
    {
        if ($hold === null) {
            return;
        }

        if ($success) {
            $this->confirmHold->handle($hold, "Consulta: {$consulta->slug}", $execution);

            return;
        }

        $this->releaseHold->handle($hold);
    }

    private function release(?WalletHold $hold): void
    {
        if ($hold !== null) {
            $this->releaseHold->handle($hold);
        }
    }
}
```

- [ ] **Step 6: Rodar os testes de cobrança E os antigos**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Consultas/CobrancaConsultaTest.php tests/Feature/Consultas/ExecutarConsultaTest.php modules/Consultas tests/Arch/ArchTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS. Os 4 testes antigos de `ExecutarConsultaTest` usam consulta sem preço (default 0), então seguem sem carteira e continuam verdes.

- [ ] **Step 7: Commit**

```bash
git add modules/Consultas app/Actions/Consultas tests/Feature/Consultas
git commit -m "feat(consultas): cobra a consulta só no resultado útil, via carteira"
```

---

### Task 11: Registrar o módulo, mutation e verde total

**Files:**
- Modify: `modules/Carteira/Providers/CarteiraServiceProvider.php` (registra o comando se necessário)
- Modify: `composer.json` (adiciona classes de Carteira ao `test:mutate`)
- Test/gate: `composer check` (partido host/Sail)

**Interfaces:**
- Produces: comando `wallet:expire-holds` descoberto; classes de invariante de dinheiro no conjunto de mutation.

- [ ] **Step 1: Garantir descoberta do comando**

Confira: `./vendor/bin/sail artisan list | grep wallet:expire-holds`. Se aparecer, o `routes/console.php` já bastou. Se **não** aparecer, adicione ao `CarteiraServiceProvider`:

```php
    #[\Override]
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([\Modules\Carteira\Console\Commands\ExpireHolds::class]);
        }
    }
```

Reconfirme a listagem depois.

- [ ] **Step 2: Adicionar as classes de dinheiro ao mutation testing**

Em `composer.json`, no script `test:mutate`, acrescente à lista `--class=` (separadas por vírgula, sem espaço) as classes de invariante:
`Modules\Carteira\Actions\ReserveCredit`, `Modules\Carteira\Actions\ConfirmHold`, `Modules\Carteira\Actions\ReleaseHold`, `Modules\Carteira\Actions\CreditWallet`, `Modules\Carteira\Models\Wallet`, `App\Actions\Consultas\ExecutarConsulta`.

Não rode o mutation agora (leva ~25min e não faz parte do `composer check`); ele roda no agendamento noturno. Só registre as classes.

- [ ] **Step 3: `composer check` partido**

Run (host): `vendor/bin/pint --dirty --format agent && composer refactor:check && composer spell:check && composer types:coverage`
Run (Sail): `./vendor/bin/sail composer types:check && ./vendor/bin/sail artisan test --exclude-testsuite=Browser`

Expected: Pint clean; Rector 0; Peck clean; type coverage 100%; PHPStan 0 (Sail); suíte verde.

- [ ] **Step 4: Corrigir o que reprovar**

Prováveis:
- **Peck**: adicione a `peck.json` (`ignore.words`, ordenado) os termos novos que aparecerem — prováveis: `carteira`, `wallet`, `holds`. (Só os que o Peck acusar.)
- **Rector**: aplique `composer refactor` (host) e reconfira; cuidado com nomes de propriedade que colidam com regras (ex.: não nomeie propriedade `sleep`).
- **PHPStan**: `lockForUpdate()->first()` é nullable — use `->firstOrFail()` onde a carteira/hold já existe. Não use cast para silenciar; complete os tipos.

- [ ] **Step 5: Commit final**

```bash
git add -A
git commit -m "chore(carteira): registra comando, mutation e fecha composer check"
```

---

## Self-Review

**Spec coverage (D3 e D4 do spec, parte de Fase B):**
- `Wallet` por time, saldo em centavos → Task 3. ✓
- `WalletTransaction` append-only com `balance_after` → Task 4. ✓
- `WalletHold` reduz o disponível → Tasks 5. ✓
- Reserva (hold) → execução → confirma no sucesso / solta na falha → Tasks 7, 8, 10. ✓
- Lock (`FOR UPDATE`) para não gastar duas vezes o mesmo saldo → Tasks 7, 8 (lock na carteira e no hold); teste de anti double-spend sequencial em Task 7. ✓
- Hold expira sozinho (job) → Task 9. ✓
- "Só cobra se houve resultado útil" plugado na `ExecutarConsulta` → Task 10. ✓
- Crédito manual (recharge/bonus/adjustment) como mecanismo → Task 6 (`CreditWallet`); a tela do admin é Fase E. ✓

**Fora do escopo de Fase B (adiado, de propósito):** recarga via gateway/PIX e webhook (Fase C — `CreditWallet` já é o ponto onde a Fase C credita); franquia de plano e `billing_mode` (Fase D — a Task 10 só usa `price_cents`, crédito puro); tela de carteira/razão do usuário e do admin (Fases E/F).

**Limitação conhecida, registrada honestamente:** o teste de concorrência **real** (duas conexões disputando a linha) não roda sob `RefreshDatabase`, que embrulha o teste numa transação de conexão única — a segunda conexão não enxergaria as linhas não commitadas. O que os testes provam é a **invariante que o lock protege**: holds reduzem o disponível e uma segunda reserva do saldo já reservado é recusada (Task 7). O `lockForUpdate` está no código para serializar escritores concorrentes; a corrida verdadeira fica coberta por inspeção e pelo mutation testing das actions. Isso está dito aqui em vez de fingir cobertura que a ferramenta não dá.

**Placeholder scan:** sem "TBD"/"TODO". Duas notas de correção inline (o `$hold->team->run` fictício na Task 8 e o `$team->wallet_charge` na Task 10) trazem a versão correta a usar — são avisos de armadilha, não placeholders.

**Type consistency:** `CreditWallet`/`ReserveCredit`/`ConfirmHold`/`ReleaseHold` mantêm as assinaturas entre definição (Tasks 6-8) e consumo (Task 10). `Wallet::forTeam`, `available()`, `holds()`, `transactions()` consistentes. `WalletHoldStatus`/`WalletTransactionType` idem. `price_cents` é o nome da coluna e do uso em todo o fluxo. `TenantManager::forTenant` é o wrap usado por todas as actions.

**Ponto de atenção para a revisão:** as actions confiam em `TenantManager::forTenant($team, ...)` para escopar; se alguma esquecer o wrap, o `BelongsToTenant` grava com o tenant ambiente (errado) ou lança fail-closed. Cada action tem teste que cria via factory e confere `team_id`/saldo — remover o wrap deve reprovar.
