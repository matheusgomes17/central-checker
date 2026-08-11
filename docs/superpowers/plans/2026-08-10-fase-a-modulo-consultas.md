# Fase A — Núcleo do módulo Consultas — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um motor que executa uma consulta contra um provider de terceiro com resiliência (timeout, retry, circuit breaker, manutenção) e registra o desfecho, sem ainda cobrar por ela.

**Architecture:** Módulo `Consultas` (via `make:module`) com fronteira testada. Um `ProviderContract` que todo terceiro implementa; um `ProviderRegistry` que mapeia chave → provider (padrão `ResourceCounters`); um `ResilientProvider` que decora qualquer provider com retry e circuit breaker. O catálogo (`Consulta`) é global; o registro de execução (`ConsultaExecution`) é escopado por time. A action de aplicação `ExecutarConsulta` compõe tudo.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, o sistema de módulos do projeto (`modules/`), cache do Laravel para o breaker, casts `encrypted` para PII em repouso.

## Global Constraints

- PHP 8.4; `declare(strict_types=1)` no topo de todo arquivo PHP.
- Prosa e comentários `//`/`/* */` em **português**; docblocks `/** */` em **inglês** (o Peck roda no CI e não fala português).
- Comentário explica o **porquê**, nunca reescreve a linha seguinte.
- Constructor property promotion; tipos explícitos em todo parâmetro e retorno; PHPDoc com array shapes.
- Um módulo depende de `Core` e de si mesmo, **nunca de um irmão**. A composição mora em `app/`.
- Todo model em `modules/*/Models` é escopado por `BelongsToTenant`, a menos que esteja em `App\Support\TenantManager::UNSCOPED_MODELS` **com justificativa escrita**.
- Model tem menos de 150 linhas e não concentra regra de negócio (regra vai para Action/Support).
- Nada de `dd`/`dump`/`env()` fora de `config/`.
- Nada de abstração sem 3 usos reais.
- Depois de mexer em PHP: `vendor/bin/pint --dirty --format agent`.
- Rodar testes com `composer test` ou filtro explícito — **nunca** `php artisan test` sem caminho (inclui a suíte Browser que trava).
- Antes de dizer que terminou: `composer check`.
- **Todo teste novo precisa ser visto falhar antes de valer.**
- Mensagem de commit em português, terminando com:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

## File Structure

```
modules/Consultas/
├── Providers/ConsultasServiceProvider.php   # registra o ProviderRegistry (singleton)
├── Contracts/ProviderContract.php           # o contrato do terceiro
├── Enums/ProviderOutcome.php                # Success | NoData | Failed
├── Enums/ConsultaStatus.php                 # Active | Maintenance | Inactive
├── Support/
│   ├── ConsultaInput.php                    # value object da entrada, com máscara
│   ├── ProviderResult.php                   # value object do desfecho
│   ├── ProviderRegistry.php                 # chave → provider
│   ├── CircuitBreaker.php                    # estado do circuito em cache
│   ├── ResilientProvider.php                # decorator: retry + breaker
│   ├── ConsultaAvailability.php             # status manual + breaker → disponível?
│   └── FakeProvider.php                      # provider de referência/teste
├── Exceptions/
│   ├── ConsultaUnavailableException.php
│   └── ProviderNotRegisteredException.php
├── Models/
│   ├── Consulta.php                         # catálogo (global/unscoped)
│   └── ConsultaExecution.php                # registro de execução (scoped)
├── Database/Factories/
│   ├── ConsultaFactory.php
│   └── ConsultaExecutionFactory.php
├── database/migrations/
│   ├── *_create_consultas_table.php
│   └── *_create_consulta_executions_table.php
└── tests/
    ├── Unit/  (value objects, breaker)
    └── Feature/ (models, registry, resilient, action)

app/Actions/Consultas/ExecutarConsulta.php   # composição (app/, não módulo)
app/Support/TenantManager.php                # +Consulta na allowlist UNSCOPED
tests/Arch/ArchTest.php                      # já cobre o módulo automaticamente
```

---

### Task 1: Scaffold do módulo Consultas

**Files:**
- Create: `modules/Consultas/` (via `php artisan make:module Consultas`)
- Modify: `config/modules.php` (o comando faz)
- Test: verificação por `composer test` (a suíte Arch já varre `modules/`)

**Interfaces:**
- Produces: o namespace `Modules\Consultas\` autoloadável e o `ConsultasServiceProvider` registrado.

- [ ] **Step 1: Criar o módulo**

Run:
```bash
php artisan make:module Consultas
composer dump-autoload
```
Expected: cria `modules/Consultas/...` e adiciona `'Consultas'` em `config/modules.php`.

- [ ] **Step 2: Rodar a suíte de arquitetura para confirmar que o módulo vazio passa**

Run: `composer test -- tests/Arch/ArchTest.php`
Expected: PASS (nenhum model ainda; o módulo só existe).

- [ ] **Step 3: Commit**

```bash
git add modules/Consultas config/modules.php
git commit -m "feat(consultas): scaffold do módulo"
```

---

### Task 2: ProviderOutcome + ProviderResult

O desfecho de uma consulta é um de três estados, e a distinção **é** a futura regra de cobrança. `ProviderResult` é imutável.

**Files:**
- Create: `modules/Consultas/Enums/ProviderOutcome.php`
- Create: `modules/Consultas/Support/ProviderResult.php`
- Test: `modules/Consultas/tests/Unit/ProviderResultTest.php`

**Interfaces:**
- Produces:
  - `enum ProviderOutcome: string { case Success = 'success'; case NoData = 'no_data'; case Failed = 'failed'; }`
  - `ProviderResult::success(array<string,mixed> $data): self`
  - `ProviderResult::noData(): self`
  - `ProviderResult::failed(string $reason): self`
  - `ProviderResult->outcome(): ProviderOutcome`
  - `ProviderResult->data(): array<string,mixed>`
  - `ProviderResult->reason(): ?string`
  - `ProviderResult->isSuccess(): bool` / `isNoData(): bool` / `isFailed(): bool`

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use Modules\Consultas\Enums\ProviderOutcome;
use Modules\Consultas\Support\ProviderResult;

it('carrega dado no sucesso', function (): void {
    $result = ProviderResult::success(['nome' => 'Fulano']);

    expect($result->outcome())->toBe(ProviderOutcome::Success)
        ->and($result->isSuccess())->toBeTrue()
        ->and($result->data())->toBe(['nome' => 'Fulano'])
        ->and($result->reason())->toBeNull();
});

it('não encontrar registro é um desfecho, não erro', function (): void {
    $result = ProviderResult::noData();

    expect($result->outcome())->toBe(ProviderOutcome::NoData)
        ->and($result->isNoData())->toBeTrue()
        ->and($result->isFailed())->toBeFalse()
        ->and($result->data())->toBe([]);
});

it('falha carrega o motivo', function (): void {
    $result = ProviderResult::failed('timeout');

    expect($result->outcome())->toBe(ProviderOutcome::Failed)
        ->and($result->isFailed())->toBeTrue()
        ->and($result->reason())->toBe('timeout');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test -- modules/Consultas/tests/Unit/ProviderResultTest.php`
Expected: FAIL (classe inexistente).

- [ ] **Step 3: Implementar o enum**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Enums;

/**
 * The three ways a provider call can end.
 *
 * The distinction is the billing rule in disguise: only Success may be charged.
 * NoData is a legitimate answer ("no record found"), not a failure, so it must
 * not be confused with Failed, which feeds the circuit breaker.
 */
enum ProviderOutcome: string
{
    case Success = 'success';
    case NoData = 'no_data';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Implementar o value object**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Modules\Consultas\Enums\ProviderOutcome;

/**
 * The immutable outcome of a single provider call.
 *
 * Built through the named constructors so an impossible state — a Failed result
 * carrying data, or a Success with no data — cannot be assembled.
 */
final class ProviderResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly ProviderOutcome $outcome,
        private readonly array $data,
        private readonly ?string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(array $data): self
    {
        return new self(ProviderOutcome::Success, $data, null);
    }

    public static function noData(): self
    {
        return new self(ProviderOutcome::NoData, [], null);
    }

    public static function failed(string $reason): self
    {
        return new self(ProviderOutcome::Failed, [], $reason);
    }

    public function outcome(): ProviderOutcome
    {
        return $this->outcome;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function isSuccess(): bool
    {
        return $this->outcome === ProviderOutcome::Success;
    }

    public function isNoData(): bool
    {
        return $this->outcome === ProviderOutcome::NoData;
    }

    public function isFailed(): bool
    {
        return $this->outcome === ProviderOutcome::Failed;
    }
}
```

- [ ] **Step 5: Rodar e ver passar; formatar**

Run: `composer test -- modules/Consultas/tests/Unit/ProviderResultTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/Consultas
git commit -m "feat(consultas): ProviderResult com os três desfechos"
```

---

### Task 3: ConsultaInput com máscara

Value object da entrada. Guarda os parâmetros e sabe produzir uma versão mascarada para gravar sem expor PII crua (D9 do spec).

**Files:**
- Create: `modules/Consultas/Support/ConsultaInput.php`
- Test: `modules/Consultas/tests/Unit/ConsultaInputTest.php`

**Interfaces:**
- Produces:
  - `ConsultaInput::fromArray(array<string,string> $params): self`
  - `ConsultaInput->all(): array<string,string>`
  - `ConsultaInput->get(string $key): ?string`
  - `ConsultaInput->masked(): array<string,string>` — mantém 3 primeiros e 2 últimos de cada valor com 6+ caracteres, o resto vira `*`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use Modules\Consultas\Support\ConsultaInput;

it('devolve os parâmetros crus', function (): void {
    $input = ConsultaInput::fromArray(['cpf' => '12345678901']);

    expect($input->all())->toBe(['cpf' => '12345678901'])
        ->and($input->get('cpf'))->toBe('12345678901')
        ->and($input->get('ausente'))->toBeNull();
});

it('mascara valores longos preservando pontas', function (): void {
    $input = ConsultaInput::fromArray(['cpf' => '12345678901', 'uf' => 'SP']);

    expect($input->masked())->toBe([
        'cpf' => '123******01',
        'uf' => 'SP', // curto demais para mascarar
    ]);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test -- modules/Consultas/tests/Unit/ConsultaInputTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Illuminate\Support\Str;

/**
 * The immutable input to a consulta.
 *
 * Owns the masking used before anything is written down: consulta inputs are
 * third-party personal data (a CPF, a document number), and the raw value must
 * never reach a log or an audit row. Short values are left alone — there is
 * nothing to hide in a two-letter state code.
 */
final class ConsultaInput
{
    /**
     * @param  array<string, string>  $params
     */
    private function __construct(private readonly array $params) {}

    /**
     * @param  array<string, string>  $params
     */
    public static function fromArray(array $params): self
    {
        return new self($params);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->params;
    }

    public function get(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function masked(): array
    {
        return array_map(
            static function (string $value): string {
                if (Str::length($value) < 6) {
                    return $value;
                }

                $keepStart = Str::substr($value, 0, 3);
                $keepEnd = Str::substr($value, -2);
                $hidden = str_repeat('*', Str::length($value) - 5);

                return $keepStart.$hidden.$keepEnd;
            },
            $this->params,
        );
    }
}
```

- [ ] **Step 4: Rodar e ver passar; formatar**

Run: `composer test -- modules/Consultas/tests/Unit/ConsultaInputTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/Consultas
git commit -m "feat(consultas): ConsultaInput com máscara de PII"
```

---

### Task 4: ProviderContract, FakeProvider e ProviderRegistry

O contrato do terceiro, um provider de referência que devolve resultados combinados (serve de teste e de demo), e o registro chave→provider.

**Files:**
- Create: `modules/Consultas/Contracts/ProviderContract.php`
- Create: `modules/Consultas/Support/FakeProvider.php`
- Create: `modules/Consultas/Support/ProviderRegistry.php`
- Create: `modules/Consultas/Exceptions/ProviderNotRegisteredException.php`
- Test: `modules/Consultas/tests/Feature/ProviderRegistryTest.php`

**Interfaces:**
- Consumes: `ProviderResult` (Task 2), `ConsultaInput` (Task 3).
- Produces:
  - `interface ProviderContract { key(): string; execute(ConsultaInput $input): ProviderResult; healthCheck(): bool; }`
  - `FakeProvider::returning(ProviderResult ...$results): self` — devolve os resultados em ordem, repetindo o último; `calls(): int`.
  - `FakeProvider::throwing(\Throwable $e): self` — lança na chamada.
  - `FakeProvider` com `key(): string` default `'fake'`; `FakeProvider::returning(...)->withKey(string $key): self`.
  - `ProviderRegistry->register(ProviderContract $provider): void` (chaveia por `$provider->key()`)
  - `ProviderRegistry->resolve(string $key): ProviderContract` (lança `ProviderNotRegisteredException` se ausente)
  - `ProviderRegistry->has(string $key): bool`
  - `ProviderRegistry->keys(): array<int,string>`

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use Modules\Consultas\Exceptions\ProviderNotRegisteredException;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\FakeProvider;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ProviderResult;

it('registra e resolve um provider pela chave', function (): void {
    $registry = new ProviderRegistry();
    $provider = FakeProvider::returning(ProviderResult::noData())->withKey('serasa');

    $registry->register($provider);

    expect($registry->has('serasa'))->toBeTrue()
        ->and($registry->resolve('serasa'))->toBe($provider)
        ->and($registry->keys())->toBe(['serasa']);
});

it('recusa resolver provider desconhecido', function (): void {
    $registry = new ProviderRegistry();

    $registry->resolve('inexistente');
})->throws(ProviderNotRegisteredException::class);

it('o fake devolve resultados em ordem e conta chamadas', function (): void {
    $provider = FakeProvider::returning(
        ProviderResult::failed('timeout'),
        ProviderResult::success(['nome' => 'Fulano']),
    );

    $input = ConsultaInput::fromArray(['cpf' => '12345678901']);

    expect($provider->execute($input)->isFailed())->toBeTrue()
        ->and($provider->execute($input)->isSuccess())->toBeTrue()
        ->and($provider->execute($input)->isSuccess())->toBeTrue() // repete o último
        ->and($provider->calls())->toBe(3);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test -- modules/Consultas/tests/Feature/ProviderRegistryTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar o contrato**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Contracts;

use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\ProviderResult;

/**
 * What a third party writes to plug a data source into the platform.
 *
 * Deliberately small: the provider owns only the call and the parsing of its
 * own response into a ProviderResult. Timeout, retry and the circuit breaker
 * live in the ResilientProvider that wraps this, so no provider reimplements
 * resilience its own way and hands us its own bugs.
 */
interface ProviderContract
{
    public function key(): string;

    public function execute(ConsultaInput $input): ProviderResult;

    public function healthCheck(): bool;
}
```

- [ ] **Step 4: Implementar a exceção**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Exceptions;

use RuntimeException;

final class ProviderNotRegisteredException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Nenhum provider registrado para a chave [{$key}].");
    }
}
```

- [ ] **Step 5: Implementar o FakeProvider**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Modules\Consultas\Contracts\ProviderContract;
use Throwable;

/**
 * A provider that returns canned results, for tests and local demos.
 *
 * Ships in Support rather than in the test suite because the registry, the
 * seeder and the local environment all need to reference it by class, and the
 * module's test directory is not on the autoload path.
 */
final class FakeProvider implements ProviderContract
{
    /**
     * @param  array<int, ProviderResult>  $results
     */
    private function __construct(
        private readonly array $results,
        private readonly ?Throwable $throws = null,
        private string $key = 'fake',
        private int $calls = 0,
    ) {}

    public static function returning(ProviderResult ...$results): self
    {
        return new self($results === [] ? [ProviderResult::noData()] : $results);
    }

    public static function throwing(Throwable $e): self
    {
        return new self([], $e);
    }

    public function withKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function execute(ConsultaInput $input): ProviderResult
    {
        $index = $this->calls;
        $this->calls++;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        // Repete o último resultado quando as chamadas passam do combinado, para
        // que um teste de retry não precise enfileirar um resultado por tentativa.
        return $this->results[$index] ?? $this->results[array_key_last($this->results)];
    }

    public function healthCheck(): bool
    {
        return $this->throws === null;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
```

- [ ] **Step 6: Implementar o registro**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Modules\Consultas\Contracts\ProviderContract;
use Modules\Consultas\Exceptions\ProviderNotRegisteredException;

/**
 * Maps a provider key to its implementation.
 *
 * Mirrors ResourceCounters: a new data source is a new class plus one line of
 * registration, and asking for a key nobody registered is an error rather than
 * a silent null — a consulta pointing at a missing provider is a bug we want
 * loud, not a screen that quietly does nothing.
 */
class ProviderRegistry
{
    /**
     * @var array<string, ProviderContract>
     */
    private array $providers = [];

    public function register(ProviderContract $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function resolve(string $key): ProviderContract
    {
        return $this->providers[$key] ?? throw ProviderNotRegisteredException::forKey($key);
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
```

- [ ] **Step 7: Rodar e ver passar; formatar**

Run: `composer test -- modules/Consultas/tests/Feature/ProviderRegistryTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add modules/Consultas
git commit -m "feat(consultas): contrato de provider, fake e registro"
```

---

### Task 5: CircuitBreaker

Estado do circuito em cache, por chave de provider. N falhas seguidas abrem; passado o resfriamento, meio-aberto testa a recuperação.

**Files:**
- Create: `modules/Consultas/Support/CircuitBreaker.php`
- Test: `modules/Consultas/tests/Feature/CircuitBreakerTest.php`

**Interfaces:**
- Produces:
  - `const int FAILURE_THRESHOLD = 5;`
  - `const int COOLDOWN_SECONDS = 60;`
  - `CircuitBreaker->recordSuccess(string $key): void` (zera o contador, fecha)
  - `CircuitBreaker->recordFailure(string $key): void` (incrementa; ao atingir o limite, abre por COOLDOWN_SECONDS)
  - `CircuitBreaker->isOpen(string $key): bool`

Usa `tests/Feature` porque toca o cache do container. O teste manipula o tempo com `Illuminate\Support\Carbon::setTestNow()` e usa o cache `array` do ambiente de teste.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Consultas\Support\CircuitBreaker;

beforeEach(function (): void {
    $this->breaker = new CircuitBreaker();
});

it('começa fechado', function (): void {
    expect($this->breaker->isOpen('serasa'))->toBeFalse();
});

it('abre depois do limite de falhas seguidas', function (): void {
    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $this->breaker->recordFailure('serasa');
    }

    expect($this->breaker->isOpen('serasa'))->toBeTrue();
});

it('sucesso no meio zera o contador', function (): void {
    $this->breaker->recordFailure('serasa');
    $this->breaker->recordFailure('serasa');
    $this->breaker->recordSuccess('serasa');
    $this->breaker->recordFailure('serasa');

    expect($this->breaker->isOpen('serasa'))->toBeFalse();
});

it('fecha de novo passado o resfriamento', function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $this->breaker->recordFailure('serasa');
    }
    expect($this->breaker->isOpen('serasa'))->toBeTrue();

    Carbon::setTestNow(Carbon::now()->addSeconds(CircuitBreaker::COOLDOWN_SECONDS + 1));
    expect($this->breaker->isOpen('serasa'))->toBeFalse();
});

it('separa o estado por chave de provider', function (): void {
    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $this->breaker->recordFailure('serasa');
    }

    expect($this->breaker->isOpen('serasa'))->toBeTrue()
        ->and($this->breaker->isOpen('receita'))->toBeFalse();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test -- modules/Consultas/tests/Feature/CircuitBreakerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Trips a provider offline after it fails too many times in a row.
 *
 * State lives in the cache, not in the consulta row: writing "under maintenance"
 * back to the database on every failed call would couple reads to writes and
 * race under concurrency. Availability is computed instead — the manual status
 * of the catalog plus this breaker being closed.
 *
 * After the cooldown the key is simply allowed through again (half-open): the
 * next call decides. A success clears the count; a failure trips it once more.
 */
class CircuitBreaker
{
    /**
     * Falhas seguidas que abrem o circuito.
     */
    public const int FAILURE_THRESHOLD = 5;

    /**
     * Segundos que o circuito permanece aberto antes de deixar passar de novo.
     */
    public const int COOLDOWN_SECONDS = 60;

    public function recordSuccess(string $key): void
    {
        Cache::forget($this->failuresKey($key));
        Cache::forget($this->openKey($key));
    }

    public function recordFailure(string $key): void
    {
        $failures = (int) Cache::get($this->failuresKey($key), 0) + 1;

        Cache::put($this->failuresKey($key), $failures, now()->addSeconds(self::COOLDOWN_SECONDS));

        if ($failures >= self::FAILURE_THRESHOLD) {
            Cache::put($this->openKey($key), true, now()->addSeconds(self::COOLDOWN_SECONDS));
        }
    }

    public function isOpen(string $key): bool
    {
        return (bool) Cache::get($this->openKey($key), false);
    }

    private function failuresKey(string $key): string
    {
        return "consultas:breaker:failures:{$key}";
    }

    private function openKey(string $key): string
    {
        return "consultas:breaker:open:{$key}";
    }
}
```

- [ ] **Step 4: Rodar e ver passar; formatar**

Run: `composer test -- modules/Consultas/tests/Feature/CircuitBreakerTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/Consultas
git commit -m "feat(consultas): circuit breaker por provider em cache"
```

---

### Task 6: ResilientProvider

Decora qualquer `ProviderContract`: consulta o breaker antes de chamar, faz retry **só** em `failed`, converte exceção em `failed`, e alimenta o breaker.

**Files:**
- Create: `modules/Consultas/Support/ResilientProvider.php`
- Test: `modules/Consultas/tests/Feature/ResilientProviderTest.php`

**Interfaces:**
- Consumes: `ProviderContract` (Task 4), `CircuitBreaker` (Task 5), `ProviderResult`/`ConsultaInput`.
- Produces:
  - `const int MAX_TRIES = 2;`
  - `new ResilientProvider(ProviderContract $inner, CircuitBreaker $breaker, int $maxTries = self::MAX_TRIES, ?Closure $sleep = null)` — `$sleep` recebe `(int $attempt): void`; default dorme `usleep`, testes passam no-op.
  - `ResilientProvider->execute(ConsultaInput $input): ProviderResult`
  - Semântica: breaker aberto → `ProviderResult::failed('circuito aberto')` sem chamar o inner. `success`/`no_data` retornam na hora (e registram sucesso/limpam o breaker no `success`; `no_data` não conta como falha nem como sucesso do breaker). `failed` ou exceção → registra falha e tenta de novo até `maxTries`; esgotado, devolve o último `failed`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use Modules\Consultas\Support\CircuitBreaker;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\FakeProvider;
use Modules\Consultas\Support\ProviderResult;
use Modules\Consultas\Support\ResilientProvider;

function noSleep(): Closure
{
    return static fn (int $attempt): null => null;
}

function input(): ConsultaInput
{
    return ConsultaInput::fromArray(['cpf' => '12345678901']);
}

it('devolve sucesso sem repetir', function (): void {
    $inner = FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']));
    $resilient = new ResilientProvider($inner, new CircuitBreaker(), sleep: noSleep());

    expect($resilient->execute(input())->isSuccess())->toBeTrue()
        ->and($inner->calls())->toBe(1);
});

it('não repete quando o desfecho é nada encontrado', function (): void {
    $inner = FakeProvider::returning(ProviderResult::noData());
    $resilient = new ResilientProvider($inner, new CircuitBreaker(), sleep: noSleep());

    expect($resilient->execute(input())->isNoData())->toBeTrue()
        ->and($inner->calls())->toBe(1);
});

it('repete em falha até o limite', function (): void {
    $inner = FakeProvider::returning(ProviderResult::failed('timeout'));
    $resilient = new ResilientProvider($inner, new CircuitBreaker(), maxTries: 2, sleep: noSleep());

    expect($resilient->execute(input())->isFailed())->toBeTrue()
        ->and($inner->calls())->toBe(2);
});

it('para de repetir assim que um sucesso aparece', function (): void {
    $inner = FakeProvider::returning(
        ProviderResult::failed('timeout'),
        ProviderResult::success(['nome' => 'Fulano']),
    );
    $resilient = new ResilientProvider($inner, new CircuitBreaker(), maxTries: 3, sleep: noSleep());

    expect($resilient->execute(input())->isSuccess())->toBeTrue()
        ->and($inner->calls())->toBe(2);
});

it('converte exceção do provider em falha', function (): void {
    $inner = FakeProvider::throwing(new RuntimeException('conexão recusada'));
    $resilient = new ResilientProvider($inner, new CircuitBreaker(), maxTries: 1, sleep: noSleep());

    $result = $resilient->execute(input());

    expect($result->isFailed())->toBeTrue()
        ->and($result->reason())->toBe('conexão recusada');
});

it('não chama o inner com o circuito aberto', function (): void {
    $breaker = new CircuitBreaker();
    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $breaker->recordFailure('fake');
    }

    $inner = FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']));
    $resilient = new ResilientProvider($inner, $breaker, sleep: noSleep());

    expect($resilient->execute(input())->isFailed())->toBeTrue()
        ->and($inner->calls())->toBe(0);
});

it('fecha o breaker ao voltar a ter sucesso', function (): void {
    $breaker = new CircuitBreaker();
    $breaker->recordFailure('fake');

    $inner = FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']));
    $resilient = new ResilientProvider($inner, $breaker, sleep: noSleep());

    $resilient->execute(input());

    // Uma falha a menos do que o limite: se o sucesso não tivesse zerado, a
    // próxima sequência de falhas abriria antes.
    expect($breaker->isOpen('fake'))->toBeFalse();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test -- modules/Consultas/tests/Feature/ResilientProviderTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Closure;
use Modules\Consultas\Contracts\ProviderContract;
use Throwable;

/**
 * Wraps a provider with the resilience every provider should have and none
 * should write: it consults the breaker before calling, retries only on a
 * technical failure, turns a thrown exception into a Failed result, and feeds
 * the breaker so a source that is down stops being hammered.
 *
 * NoData is never retried and never counts against the breaker — it is a real
 * answer. Only Failed does both.
 */
final class ResilientProvider
{
    /**
     * Tentativas no total (não retentativas): 2 = uma repetição.
     */
    public const int MAX_TRIES = 2;

    /**
     * @param  (Closure(int): void)|null  $sleep
     */
    public function __construct(
        private readonly ProviderContract $inner,
        private readonly CircuitBreaker $breaker,
        private readonly int $maxTries = self::MAX_TRIES,
        private readonly ?Closure $sleep = null,
    ) {}

    public function execute(ConsultaInput $input): ProviderResult
    {
        $key = $this->inner->key();

        if ($this->breaker->isOpen($key)) {
            return ProviderResult::failed('circuito aberto');
        }

        $result = ProviderResult::failed('não executado');

        for ($attempt = 1; $attempt <= $this->maxTries; $attempt++) {
            $result = $this->attempt($input);

            if (! $result->isFailed()) {
                if ($result->isSuccess()) {
                    $this->breaker->recordSuccess($key);
                }

                return $result;
            }

            $this->breaker->recordFailure($key);

            if ($attempt < $this->maxTries) {
                $this->backoff($attempt);
            }
        }

        return $result;
    }

    private function attempt(ConsultaInput $input): ProviderResult
    {
        try {
            return $this->inner->execute($input);
        } catch (Throwable $e) {
            return ProviderResult::failed($e->getMessage());
        }
    }

    private function backoff(int $attempt): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($attempt);

            return;
        }

        // Espera crescente entre tentativas, em milissegundos. Sob requisição
        // síncrona o breaker é a proteção principal; a repetição é para a falha
        // transitória, não para insistir num provider fora do ar.
        usleep($attempt * 200_000);
    }
}
```

- [ ] **Step 4: Rodar e ver passar; formatar**

Run: `composer test -- modules/Consultas/tests/Feature/ResilientProviderTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/Consultas
git commit -m "feat(consultas): ResilientProvider com retry e breaker"
```

---

### Task 7: Model Consulta (catálogo global) + status + disponibilidade

O catálogo é global (o operador define, todos veem) → **não** escopado. Entra na allowlist com justificativa.

**Files:**
- Create: `modules/Consultas/Enums/ConsultaStatus.php`
- Create: `modules/Consultas/Models/Consulta.php`
- Create: `modules/Consultas/Database/Factories/ConsultaFactory.php`
- Create: `modules/Consultas/database/migrations/*_create_consultas_table.php`
- Create: `modules/Consultas/Support/ConsultaAvailability.php`
- Modify: `app/Support/TenantManager.php` (adiciona `Consulta` a `UNSCOPED_MODELS` com justificativa)
- Test: `modules/Consultas/tests/Feature/ConsultaAvailabilityTest.php`

**Interfaces:**
- Consumes: `CircuitBreaker` (Task 5).
- Produces:
  - `enum ConsultaStatus: string { case Active = 'active'; case Maintenance = 'maintenance'; case Inactive = 'inactive'; }`
  - `Consulta` com colunas: `id, slug, name, description, category, provider_key, status (ConsultaStatus), timeout_seconds, timestamps`. `$fillable` = `['slug','name','description','category','provider_key','status','timeout_seconds']`; cast `status => ConsultaStatus::class`.
  - `ConsultaAvailability->available(Consulta $consulta): bool` = `status === Active && ! breaker->isOpen(provider_key)`.

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
        Schema::create('consultas', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('category')->nullable();
            $table->string('provider_key');
            $table->string('status')->default('active');
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
    }
};
```

- [ ] **Step 2: Escrever o enum e o factory**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Enums;

/**
 * The manual availability the operator sets on a consulta.
 *
 * Separate from the circuit breaker: this is the human decision, the breaker is
 * the automatic one. A consulta is offered only when both agree.
 */
enum ConsultaStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
}
```

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consultas\Enums\ConsultaStatus;
use Modules\Consultas\Models\Consulta;

/**
 * @extends Factory<Consulta>
 */
class ConsultaFactory extends Factory
{
    protected $model = Consulta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'slug' => str($name)->slug()->value(),
            'name' => ucfirst((string) $name),
            'description' => $this->faker->sentence(),
            'category' => 'cadastral',
            'provider_key' => 'fake',
            'status' => ConsultaStatus::Active,
            'timeout_seconds' => 30,
        ];
    }

    public function inMaintenance(): self
    {
        return $this->state(['status' => ConsultaStatus::Maintenance]);
    }
}
```

- [ ] **Step 3: Escrever o model**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Consultas\Database\Factories\ConsultaFactory;
use Modules\Consultas\Enums\ConsultaStatus;

/**
 * A purchasable query in the catalog — what the user sees and picks.
 *
 * Global on purpose: the operator defines the catalog and every user sees the
 * same one, so it is not tenant-scoped. It is listed in
 * TenantManager::UNSCOPED_MODELS with that reason.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string $provider_key
 * @property ConsultaStatus $status
 * @property int $timeout_seconds
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable(['slug', 'name', 'description', 'category', 'provider_key', 'status', 'timeout_seconds'])]
class Consulta extends Model
{
    /** @use HasFactory<ConsultaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConsultaStatus::class,
        ];
    }
}
```

- [ ] **Step 4: Adicionar Consulta à allowlist (justificada)**

Em `app/Support/TenantManager.php`, dentro de `UNSCOPED_MODELS`, acrescentar:

```php
        // Catálogo global: o operador define as consultas e todos os times veem
        // o mesmo conjunto. Não pertence a time nenhum, então não é escopado.
        \Modules\Consultas\Models\Consulta::class,
```

- [ ] **Step 5: Escrever o serviço de disponibilidade**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Support;

use Modules\Consultas\Enums\ConsultaStatus;
use Modules\Consultas\Models\Consulta;

/**
 * Whether a consulta may run right now.
 *
 * Kept out of the model so the model stays a thin data holder: availability is
 * two things agreeing — the operator's manual status and the breaker being
 * closed for the provider behind it.
 */
final class ConsultaAvailability
{
    public function __construct(private readonly CircuitBreaker $breaker) {}

    public function available(Consulta $consulta): bool
    {
        if ($consulta->status !== ConsultaStatus::Active) {
            return false;
        }

        return ! $this->breaker->isOpen($consulta->provider_key);
    }
}
```

- [ ] **Step 6: Escrever o teste**

```php
<?php

declare(strict_types=1);

use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Support\CircuitBreaker;
use Modules\Consultas\Support\ConsultaAvailability;

beforeEach(function (): void {
    $this->availability = new ConsultaAvailability($breaker = new CircuitBreaker());
    $this->breaker = $breaker;
});

it('consulta ativa com circuito fechado está disponível', function (): void {
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    expect($this->availability->available($consulta))->toBeTrue();
});

it('consulta em manutenção manual não está disponível', function (): void {
    $consulta = Consulta::factory()->inMaintenance()->create();

    expect($this->availability->available($consulta))->toBeFalse();
});

it('circuito aberto derruba a disponibilidade mesmo com status ativo', function (): void {
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    foreach (range(1, CircuitBreaker::FAILURE_THRESHOLD) as $ignored) {
        $this->breaker->recordFailure('fake');
    }

    expect($this->availability->available($consulta))->toBeFalse();
});
```

- [ ] **Step 7: Rodar e ver passar (inclui a suíte Arch, que valida a allowlist)**

Run: `composer test -- modules/Consultas/tests/Feature/ConsultaAvailabilityTest.php tests/Arch/ArchTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS. Se a Arch reclamar de `Consulta` sem trait, confirme que o Step 4 foi aplicado.

- [ ] **Step 8: Commit**

```bash
git add modules/Consultas app/Support/TenantManager.php
git commit -m "feat(consultas): catálogo Consulta global e disponibilidade"
```

---

### Task 8: Model ConsultaExecution (escopado por time)

Registra cada execução — todos os desfechos, para o relatório de taxa de sucesso por provider. Resultado bruto cifrado em repouso (D9); entrada só mascarada.

**Files:**
- Create: `modules/Consultas/Models/ConsultaExecution.php`
- Create: `modules/Consultas/Database/Factories/ConsultaExecutionFactory.php`
- Create: `modules/Consultas/database/migrations/*_create_consulta_executions_table.php`
- Test: `modules/Consultas/tests/Feature/ConsultaExecutionTest.php`

**Interfaces:**
- Consumes: `ProviderOutcome` (Task 2), `Consulta` (Task 7), `BelongsToTenant` (app).
- Produces:
  - `ConsultaExecution` com: `id, team_id, consulta_id, user_id (nullable), outcome (ProviderOutcome), duration_ms, input_masked (array), result (array|null, encrypted), failure_reason (nullable), timestamps`.
  - `$fillable` = `['consulta_id','user_id','outcome','duration_ms','input_masked','result','failure_reason']`.
  - casts: `outcome => ProviderOutcome::class`, `input_masked => 'array'`, `result => 'encrypted:array'`.

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
        Schema::create('consulta_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consulta_id')->constrained('consultas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome');
            $table->unsignedInteger('duration_ms');
            $table->json('input_masked');
            // Cifrado em repouso pelo cast do model; texto porque o corpo cifrado
            // não cabe garantidamente num JSON nativo.
            $table->text('result')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // O escopo global consulta por team_id em toda leitura.
            $table->index('team_id');
            // O relatório de taxa de sucesso agrupa por consulta e desfecho.
            $table->index(['consulta_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consulta_executions');
    }
};
```

- [ ] **Step 2: Escrever o factory**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consultas\Enums\ProviderOutcome;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Models\ConsultaExecution;

/**
 * @extends Factory<ConsultaExecution>
 */
class ConsultaExecutionFactory extends Factory
{
    protected $model = ConsultaExecution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // O time vem explícito para servir aos testes que montam dois times.
            'team_id' => Team::factory(),
            'consulta_id' => Consulta::factory(),
            'user_id' => null,
            'outcome' => ProviderOutcome::Success,
            'duration_ms' => $this->faker->numberBetween(50, 2000),
            'input_masked' => ['cpf' => '123******01'],
            'result' => ['nome' => 'Fulano'],
            'failure_reason' => null,
        ];
    }
}
```

- [ ] **Step 3: Escrever o model**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Consultas\Database\Factories\ConsultaExecutionFactory;
use Modules\Consultas\Enums\ProviderOutcome;

/**
 * The record of a single consulta run, for history and provider reporting.
 *
 * Scoped by team: an execution belongs to whoever ran it. The raw result is
 * encrypted at rest and the input is stored masked — never the raw PII — so a
 * leaked backup or a careless query does not expose a third party's data.
 *
 * @property int $id
 * @property int $team_id
 * @property int $consulta_id
 * @property int|null $user_id
 * @property ProviderOutcome $outcome
 * @property int $duration_ms
 * @property array<string, string> $input_masked
 * @property array<string, mixed>|null $result
 * @property string|null $failure_reason
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable(['consulta_id', 'user_id', 'outcome', 'duration_ms', 'input_masked', 'result', 'failure_reason'])]
class ConsultaExecution extends Model
{
    /** @use HasFactory<ConsultaExecutionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => ProviderOutcome::class,
            'input_masked' => 'array',
            'result' => 'encrypted:array',
        ];
    }
}
```

- [ ] **Step 4: Escrever o teste**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Facades\DB;
use Modules\Consultas\Models\ConsultaExecution;

it('grava o resultado cifrado em repouso', function (): void {
    $team = Team::factory()->create();
    app(TenantManager::class)->set($team);

    $execution = ConsultaExecution::factory()->create([
        'team_id' => $team->id,
        'result' => ['nome' => 'Fulano Secreto'],
    ]);

    // Pela relação, o cast decifra.
    expect($execution->fresh()->result)->toBe(['nome' => 'Fulano Secreto']);

    // Cru no banco, o texto não contém o valor.
    $raw = DB::table('consulta_executions')->where('id', $execution->id)->value('result');
    expect($raw)->not->toContain('Fulano Secreto');
});

it('é escopado por time — um time não vê a execução do outro', function (): void {
    $tenants = app(TenantManager::class);

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $tenants->set($teamA);
    ConsultaExecution::factory()->create(['team_id' => $teamA->id]);

    $tenants->set($teamB);

    expect(ConsultaExecution::query()->count())->toBe(0);
});
```

- [ ] **Step 5: Rodar e ver passar**

Run: `composer test -- modules/Consultas/tests/Feature/ConsultaExecutionTest.php tests/Arch/ArchTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS (a Arch confirma que o model escopado usa a trait).

- [ ] **Step 6: Commit**

```bash
git add modules/Consultas
git commit -m "feat(consultas): registro de execução escopado e cifrado"
```

---

### Task 9: Action ExecutarConsulta

A composição, em `app/` (ponto que pode depender do módulo). Resolve o provider, decora com resiliência, executa cronometrando, e grava a execução no desfecho — sem cobrar.

**Files:**
- Create: `app/Actions/Consultas/ExecutarConsulta.php` (via `php artisan make:action Consultas/ExecutarConsulta`, depois substituir o corpo)
- Create: `modules/Consultas/Exceptions/ConsultaUnavailableException.php`
- Test: `tests/Feature/Consultas/ExecutarConsultaTest.php`

**Interfaces:**
- Consumes: `ProviderRegistry`, `ResilientProvider`, `CircuitBreaker`, `ConsultaAvailability`, `ConsultaInput`, `Consulta`, `ConsultaExecution`, `ProviderResult`.
- Produces:
  - `ExecutarConsulta->handle(Consulta $consulta, ConsultaInput $input, ?User $user = null): ConsultaExecution`
  - Guarda: consulta indisponível → `ConsultaUnavailableException`. Provider ausente → `ProviderNotRegisteredException` (propaga do registry).
  - Grava `ConsultaExecution` com `outcome`, `duration_ms`, `input_masked = $input->masked()`, `result = $result->data()` (só no sucesso; senão `null`), `failure_reason = $result->reason()`.

- [ ] **Step 1: Escrever a exceção**

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Exceptions;

use Modules\Consultas\Models\Consulta;
use RuntimeException;

final class ConsultaUnavailableException extends RuntimeException
{
    public static function for(Consulta $consulta): self
    {
        return new self("A consulta [{$consulta->slug}] está indisponível no momento.");
    }
}
```

- [ ] **Step 2: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use App\Actions\Consultas\ExecutarConsulta;
use App\Models\Team;
use App\Support\TenantManager;
use Modules\Consultas\Enums\ProviderOutcome;
use Modules\Consultas\Exceptions\ConsultaUnavailableException;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\FakeProvider;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ProviderResult;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    app(TenantManager::class)->set($this->team);
    $this->registry = app(ProviderRegistry::class);
});

function runInput(): ConsultaInput
{
    return ConsultaInput::fromArray(['cpf' => '12345678901']);
}

it('grava execução de sucesso com o resultado', function (): void {
    $this->registry->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    $execution = app(ExecutarConsulta::class)->handle($consulta, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::Success)
        ->and($execution->result)->toBe(['nome' => 'Fulano'])
        ->and($execution->failure_reason)->toBeNull()
        ->and($execution->input_masked)->toBe(['cpf' => '123******01'])
        ->and($execution->team_id)->toBe($this->team->id);
});

it('grava nada encontrado sem resultado', function (): void {
    $this->registry->register(FakeProvider::returning(ProviderResult::noData()));
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    $execution = app(ExecutarConsulta::class)->handle($consulta, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::NoData)
        ->and($execution->result)->toBeNull();
});

it('grava falha com o motivo', function (): void {
    $this->registry->register(FakeProvider::returning(ProviderResult::failed('timeout')));
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    $execution = app(ExecutarConsulta::class)->handle($consulta, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::Failed)
        ->and($execution->failure_reason)->toBe('timeout')
        ->and($execution->result)->toBeNull();
});

it('recusa executar consulta em manutenção', function (): void {
    $this->registry->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $consulta = Consulta::factory()->inMaintenance()->create(['provider_key' => 'fake']);

    app(ExecutarConsulta::class)->handle($consulta, runInput());
})->throws(ConsultaUnavailableException::class);
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `composer test -- tests/Feature/Consultas/ExecutarConsultaTest.php`
Expected: FAIL (action inexistente).

- [ ] **Step 4: Implementar a action**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Consultas;

use App\Models\User;
use Modules\Consultas\Exceptions\ConsultaUnavailableException;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Models\ConsultaExecution;
use Modules\Consultas\Support\CircuitBreaker;
use Modules\Consultas\Support\ConsultaAvailability;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ResilientProvider;

/**
 * Runs one consulta and records the outcome.
 *
 * Lives in app/ because it is the composition point: it is the only place that
 * touches both the Consultas module's engine and the tenant-scoped execution
 * record. It does not charge — billing is a later phase; here we run and write
 * down what happened, every outcome included, because the provider success rate
 * report needs the failures too.
 */
class ExecutarConsulta
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ConsultaAvailability $availability,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function handle(Consulta $consulta, ConsultaInput $input, ?User $user = null): ConsultaExecution
    {
        if (! $this->availability->available($consulta)) {
            throw ConsultaUnavailableException::for($consulta);
        }

        $provider = $this->registry->resolve($consulta->provider_key);
        $resilient = new ResilientProvider($provider, $this->breaker);

        $startedAt = hrtime(true);
        $result = $resilient->execute($input);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return ConsultaExecution::create([
            'consulta_id' => $consulta->id,
            'user_id' => $user?->id,
            'outcome' => $result->outcome(),
            'duration_ms' => $durationMs,
            'input_masked' => $input->masked(),
            'result' => $result->isSuccess() ? $result->data() : null,
            'failure_reason' => $result->reason(),
        ]);
    }
}
```

- [ ] **Step 5: Rodar e ver passar; formatar**

Run: `composer test -- tests/Feature/Consultas/ExecutarConsultaTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Consultas modules/Consultas
git commit -m "feat(consultas): action ExecutarConsulta compõe motor e registro"
```

---

### Task 10: Registrar o ProviderRegistry como singleton no módulo

O registro precisa ser singleton para as registrações sobreviverem à requisição. Providers reais entram aqui nas fases seguintes; em Fase A a produção começa vazia e os testes registram o `FakeProvider`.

**Files:**
- Modify: `modules/Consultas/Providers/ConsultasServiceProvider.php`
- Test: `modules/Consultas/tests/Feature/ConsultasServiceProviderTest.php`

**Interfaces:**
- Consumes: `ProviderRegistry`, `CircuitBreaker`, `ConsultaAvailability`.
- Produces: `app(ProviderRegistry::class)` devolve sempre a mesma instância; `CircuitBreaker` e `ConsultaAvailability` resolvíveis pelo container.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

declare(strict_types=1);

use Modules\Consultas\Support\ConsultaAvailability;
use Modules\Consultas\Support\ProviderRegistry;

it('resolve o registro como singleton', function (): void {
    expect(app(ProviderRegistry::class))->toBe(app(ProviderRegistry::class));
});

it('resolve os serviços de suporte do módulo', function (): void {
    expect(app(ConsultaAvailability::class))->toBeInstanceOf(ConsultaAvailability::class);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test -- modules/Consultas/tests/Feature/ConsultasServiceProviderTest.php`
Expected: FAIL (registry não é singleton — cada resolução é uma instância nova).

- [ ] **Step 3: Implementar o registerModule**

Substituir o corpo do provider gerado por:

```php
<?php

declare(strict_types=1);

namespace Modules\Consultas\Providers;

use Modules\Consultas\Support\ProviderRegistry;
use Modules\Core\Providers\ModuleServiceProvider;
use Override;

class ConsultasServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Consultas';
    }

    #[Override]
    protected function registerModule(): void
    {
        // Singleton para as registrações de provider sobreviverem à requisição.
        // Providers reais entram aqui nas fases seguintes; em Fase A começa vazio.
        $this->app->singleton(ProviderRegistry::class);
    }
}
```

- [ ] **Step 4: Rodar e ver passar; formatar**

Run: `composer test -- modules/Consultas/tests/Feature/ConsultasServiceProviderTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS. (`CircuitBreaker` e `ConsultaAvailability` já resolvem por auto-wiring; o teste confirma.)

- [ ] **Step 5: Commit**

```bash
git add modules/Consultas
git commit -m "feat(consultas): ProviderRegistry singleton no módulo"
```

---

### Task 11: Verde total da fase

**Files:** nenhum novo — porta de saída.

- [ ] **Step 1: Rodar a verificação completa**

Run: `composer check`
Expected: exit 0 — Pint, Rector (dry), PHPStan `max`, Peck e a suíte Pest (sem Browser) todos verdes, 100% de type coverage.

- [ ] **Step 2: Se algo reprovar, corrigir e repetir**

Casos prováveis:
- PHPStan pedindo generic mais específico num `array` → completar o array shape no PHPDoc.
- Peck acusando palavra em docblock → docblock é inglês; reescrever.
- Type coverage < 100% → tipo faltando em parâmetro/retorno novo.

- [ ] **Step 3: Commit final se houver ajustes**

```bash
git add -A
git commit -m "chore(consultas): fecha Fase A com composer check verde"
```

---

## Self-Review

**Spec coverage (Fase A do spec):**
- Contrato do provider com três desfechos → Tasks 2, 4. ✓
- Registro chave→provider (padrão ResourceCounters) → Task 4. ✓
- `ResilientProvider` com timeout/retry/circuit breaker → Tasks 5, 6. (Timeout: coluna `timeout_seconds` no catálogo, Task 7, consumida por providers HTTP reais em fase futura; em Fase A o FakeProvider não exercita rede — registrado como limite conhecido.)
- Manutenção automática (breaker) + manual (status) → Tasks 5, 7 (`ConsultaAvailability`). ✓
- Catálogo `Consulta` (global/unscoped, justificado) → Task 7. ✓
- Execução `ConsultaExecution` (scoped, todos os desfechos, PII cifrada) → Task 8. ✓
- Provider falso para teste (e referência/demo) → Task 4 (`FakeProvider`). ✓
- Composição em `app/` sem cruzar módulos irmãos → Task 9. ✓
- "Roda e registra, sem cobrar" → Task 9 não toca carteira. ✓

**Fora do escopo de Fase A (confirmado adiado):** preço/modo de cobrança e reserva de saldo (Fase B); franquia/plano (Fase D); execução assíncrona com Reverb (fora de escopo até haver consulta lenta); TTL/expurgo do resultado (a cifra em repouso entra agora; o job de retenção entra na Fase F com as telas). Nenhum desses tem task aqui **de propósito**.

**Placeholder scan:** sem "TBD"/"TODO"; todo passo tem código real.

**Type consistency:** `ProviderResult`/`ProviderOutcome`/`ConsultaInput`/`ProviderContract`/`ProviderRegistry`/`CircuitBreaker`/`ResilientProvider`/`ConsultaAvailability`/`ExecutarConsulta` mantêm as mesmas assinaturas entre a definição e o consumo. `FakeProvider::returning()/throwing()/withKey()/calls()` usados de forma consistente nas Tasks 4, 6, 9. `provider_key` é o nome da coluna e da chave em todo o fluxo.

**Limite conhecido registrado:** o timeout real por consulta (coluna `timeout_seconds`) só é exercitado quando existir um provider HTTP de verdade; o FakeProvider não abre conexão. Está na coluna do catálogo desde já para o provider real da próxima fase lê-lo, e isso está dito no texto da Task 7 em vez de fingir cobertura que não há.
