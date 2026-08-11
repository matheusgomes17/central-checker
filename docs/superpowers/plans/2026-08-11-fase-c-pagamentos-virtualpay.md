# Fase C — Pagamentos VirtualPay (recarga Pix) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recarregar a carteira via Pix: criar uma cobrança na VirtualPay, receber o webhook assinado e idempotente, e creditar o time — com a assinatura autenticando a chamada.

**Architecture:** Módulo `Pagamentos` (via `make:module`). Um contrato `PaymentGateway` (driver `VirtualPayGateway` chama `POST /transactions`) e um contrato `WebhookVerifier` (driver `VirtualPayWebhookVerifier`, HMAC-SHA256 hex do corpo cru). Um model `PaymentIntent` (escopado por time) guarda a cobrança e liga `external_id`/`reference` → time, porque o webhook chega sem tenant. A action `CreateRecharge` cria a cobrança; o `WebhookController` verifica a assinatura (fail-closed), deduplica, e credita a carteira chamando `CreditWallet` (Fase B). **Não** remove o Cashier — isso é Fase D.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, `Http` client (com `Http::fake` nos testes), HMAC-SHA256, módulos do projeto.

## Contrato real da VirtualPay (confirmado empiricamente contra o log do legado)

- Base `https://api.virtualpay.online/v2`; auth por headers `x-client-id` + `x-client-secret`.
- **Criar cobrança:** `POST /transactions` `{type:"deposit", payment_method:"pix", amount:<reais decimal>, description, reference, email, name, document?, phone?}` → `{id, copy_paste, qr_code, status}`. `amount` é **reais decimais** (ex.: `10.00`), não centavos. `reference` é nosso identificador externo.
- **Webhook:** eventos `deposit.paid` / `transaction.updated`, `data.status === "paid"`. Headers `X-Gateway-Signature` (64 hex) e `Idempotency-Key` (64 hex). Payload: `{id, event, occurred_at, data:{amount:"10.00", status:"paid", reference:"RECHARGE-…", transaction_id:"<uuid>", …}}`. `data.transaction_id` é o UUID do pagamento (**diferente** do `id` devolvido na criação).
- **Assinatura (medido):** o corpo assinado é o **JSON cru compacto** (reconstrução byte-exata = `Content-Length`). Assinatura de 64 hex = **HMAC-SHA256 hex do corpo cru** com um **webhook secret dedicado** (não é o client-secret — provado: nenhum HMAC com client-secret/client-id bate a assinatura real). O algoritmo exato se **confirma** rodando o verificador contra a amostra real do log assim que o webhook secret real estiver em `VIRTUALPAY_WEBHOOK_SECRET`.

## Global Constraints

- PHP 8.4; `declare(strict_types=1)` no topo de todo arquivo PHP.
- Prosa e comentários em **português**; docblocks `/** */` em **inglês** (Peck roda no CI).
- Constructor property promotion; tipos explícitos; PHPDoc com array shapes.
- Um módulo depende de `Core` e de si mesmo, **nunca de um irmão**. A composição mora em `app/`. O `WebhookController` fica em `app/` (compõe Pagamentos + Carteira). O módulo `Pagamentos` **não** importa `Modules\Carteira\*` nem `Modules\Consultas\*`.
- Models de domínio escopados por `BelongsToTenant`; `team_id` **não** fillable. `PaymentIntent` é escopado; o webhook o encontra com `acrossTenants()` (cruzamento explícito e visível), como o webhook da Stripe encontra a assinatura pelo id sem tenant.
- **Dinheiro é inteiro em centavos** internamente. A VirtualPay fala reais decimais na fronteira: converter com `(int) round($reais * 100)` na entrada e `number_format($cents/100, 2, '.', '')` na saída.
- **Segredos só em `.env`/config, nunca em código.** As credenciais do legado vazaram e devem ser **rotacionadas** antes do uso.
- **Webhook fail-closed:** sem `VIRTUALPAY_WEBHOOK_SECRET` configurado, ou com assinatura inválida, o endpoint **recusa** (não credita). A assinatura é o que autentica — o endpoint não tem sessão nem CSRF.
- Nada de `dd`/`dump`/`env()` fora de `config/`.
- **Branch `main`** (sem novas branches). **Testes via Sail** (`./vendor/bin/sail artisan test --compact <path>`; suíte inteira `./vendor/bin/sail artisan test --exclude-testsuite=Browser`; nunca `sail up`, nunca `composer test -- <path>`). Static split host/Sail. Pint `vendor/bin/pint --dirty --format agent` no host após PHP.
- **Todo teste novo precisa ser visto falhar antes de valer.**
- Commit em português terminando com: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

## File Structure

```
config/services.php                      # +bloco virtualpay (base_url, client_id, client_secret, webhook_secret)
config/billing.php                       # +recharge min/max/expiration, +virtualpay_integration flag
.env.example                             # +VIRTUALPAY_* (vazias) com comentário

modules/Pagamentos/
├── Providers/PagamentosServiceProvider.php   # bind PaymentGateway->VirtualPay, WebhookVerifier->VirtualPay
├── Contracts/{PaymentGateway,WebhookVerifier}.php
├── Support/{GatewayCharge,PayerData}.php      # DTOs imutáveis
├── Enums/{PaymentStatus,PaymentPurpose}.php
├── Gateways/VirtualPayGateway.php             # POST /transactions
├── Webhooks/VirtualPayWebhookVerifier.php     # HMAC-SHA256 hex do corpo cru
├── Models/PaymentIntent.php                   # escopado; external_id/reference -> team
├── Actions/CreateRecharge.php                 # cria intent + cobrança
├── Exceptions/{RechargeAmountException,PaymentGatewayException}.php
├── Database/Factories/PaymentIntentFactory.php
├── database/migrations/*_create_payment_intents_table.php
└── tests/Feature/*

app/Http/Controllers/VirtualPayWebhookController.php   # verifica assinatura, dedup, credita carteira
routes/web.php                                          # +rota do webhook, fora do grupo web
```

---

### Task 1: Scaffold do módulo + configuração de credenciais

**Files:**
- Create: `modules/Pagamentos/` (via `make:module`); Modify: `config/modules.php`
- Modify: `config/services.php`, `config/billing.php`, `.env.example`
- Test: `modules/Pagamentos/tests/Feature/ConfigTest.php`

**Interfaces:**
- Produces: `config('services.virtualpay.base_url'|'client_id'|'client_secret'|'webhook_secret')`; `config('billing.recharge.min_cents'|'max_cents'|'expiration_seconds')`; `config('billing.virtualpay_integration')` (bool).

- [ ] **Step 1: Criar o módulo**

Run: `php artisan make:module Pagamentos && composer dump-autoload`

- [ ] **Step 2: Config de serviços** — em `config/services.php` acrescentar:

```php
    'virtualpay' => [
        'base_url' => env('VIRTUALPAY_API_URL', 'https://api.virtualpay.online/v2'),
        'client_id' => env('VIRTUALPAY_CLIENT_ID'),
        'client_secret' => env('VIRTUALPAY_CLIENT_SECRET'),
        'webhook_secret' => env('VIRTUALPAY_WEBHOOK_SECRET'),
    ],
```

- [ ] **Step 3: Config de recarga** — em `config/billing.php` acrescentar antes do `];` final:

```php
    'recharge' => [
        // Limites da recarga por Pix, em centavos. A VirtualPay fala reais na
        // fronteira; aqui é centavos como o resto do dinheiro do sistema.
        'min_cents' => (int) env('RECHARGE_MIN_CENTS', 1000),
        'max_cents' => (int) env('RECHARGE_MAX_CENTS', 100000),
        'expiration_seconds' => (int) env('RECHARGE_EXPIRATION_SECONDS', 3600),
    ],

    'virtualpay_integration' => (bool) env('VIRTUALPAY_INTEGRATION', false),
```

- [ ] **Step 4: `.env.example`** — acrescentar, com comentário, ao lado do bloco Stripe:

```
# VirtualPay (gateway Pix nacional). Rotacione qualquer credencial que já tenha vazado.
VIRTUALPAY_API_URL=https://api.virtualpay.online/v2
VIRTUALPAY_CLIENT_ID=
VIRTUALPAY_CLIENT_SECRET=
VIRTUALPAY_WEBHOOK_SECRET=
VIRTUALPAY_INTEGRATION=false
```

- [ ] **Step 5: Teste de config (falha primeiro)**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

it('expõe a configuração da VirtualPay e da recarga', function (): void {
    expect(Config::string('services.virtualpay.base_url'))->toBe('https://api.virtualpay.online/v2')
        ->and(Config::integer('billing.recharge.min_cents'))->toBe(1000)
        ->and(Config::integer('billing.recharge.max_cents'))->toBe(100000)
        ->and(Config::boolean('billing.virtualpay_integration'))->toBeFalse();
});
```

Run: `./vendor/bin/sail artisan test --compact modules/Pagamentos/tests/Feature/ConfigTest.php tests/Arch/ArchTest.php && vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit** — `git add modules/Pagamentos config .env.example && git commit`
  message: `feat(pagamentos): scaffold do módulo e config da VirtualPay`

---

### Task 2: Contratos e DTOs

**Files:**
- Create: `modules/Pagamentos/Contracts/PaymentGateway.php`, `modules/Pagamentos/Contracts/WebhookVerifier.php`, `modules/Pagamentos/Support/GatewayCharge.php`, `modules/Pagamentos/Support/PayerData.php`
- Test: `modules/Pagamentos/tests/Unit/GatewayChargeTest.php`

**Interfaces:**
- Produces:
  - `interface WebhookVerifier { public function verify(string $rawBody, string $signature): bool; }`
  - `interface PaymentGateway { public function createPixCharge(int $amountCents, string $reference, PayerData $payer): GatewayCharge; }`
  - `final class PayerData` — readonly: `name (string)`, `email (string)`, `document (?string)`, `phone (?string)`; `PayerData::for(string $name, string $email, ?string $document = null, ?string $phone = null): self`.
  - `final class GatewayCharge` — readonly: `externalId (string)`, `copyPaste (string)`, `qrCode (?string)`, `status (string)`; named ctor `GatewayCharge::from(string $externalId, string $copyPaste, ?string $qrCode, string $status): self` + getters.

- [ ] **Step 1: Teste do DTO (falha primeiro)**

```php
<?php

declare(strict_types=1);

use Modules\Pagamentos\Support\GatewayCharge;

it('carrega os dados da cobrança criada', function (): void {
    $charge = GatewayCharge::from('uuid-1', '000201...', 'base64==', 'pending');

    expect($charge->externalId())->toBe('uuid-1')
        ->and($charge->copyPaste())->toBe('000201...')
        ->and($charge->qrCode())->toBe('base64==')
        ->and($charge->status())->toBe('pending');
});
```

- [ ] **Step 2: Ver falhar, implementar os quatro arquivos, ver passar**

`WebhookVerifier` e `PaymentGateway` são interfaces (métodos acima, docblocks em inglês). `PayerData` e `GatewayCharge` são `final` com propriedades `private readonly`, named constructor e getters tipados. Rode:
`./vendor/bin/sail artisan test --compact modules/Pagamentos/tests/Unit/GatewayChargeTest.php && vendor/bin/pint --dirty --format agent`

- [ ] **Step 3: Commit** — `feat(pagamentos): contratos de gateway/verifier e DTOs`

---

### Task 3: VirtualPayWebhookVerifier (HMAC do corpo cru)

**Files:**
- Create: `modules/Pagamentos/Webhooks/VirtualPayWebhookVerifier.php`
- Test: `modules/Pagamentos/tests/Feature/VirtualPayWebhookVerifierTest.php`
- Test (opt-in): `modules/Pagamentos/tests/Feature/VirtualPaySignatureSampleTest.php`

**Interfaces:**
- Consumes: `WebhookVerifier` (Task 2).
- Produces: `VirtualPayWebhookVerifier implements WebhookVerifier` — construtor recebe o secret (`private readonly string $secret`); `verify(string $rawBody, string $signature): bool` = `$secret !== '' && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)` (constante no tempo, **fail-closed** com secret vazio).

- [ ] **Step 1: Teste offline round-trip (falha primeiro)**

```php
<?php

declare(strict_types=1);

use Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier;

it('aceita assinatura HMAC-SHA256 hex do corpo cru', function (): void {
    $secret = 'whsec_teste';
    $body = '{"event":"deposit.paid","data":{"status":"paid"}}';
    $signature = hash_hmac('sha256', $body, $secret);

    expect((new VirtualPayWebhookVerifier($secret))->verify($body, $signature))->toBeTrue();
});

it('recusa assinatura de outro segredo', function (): void {
    $body = '{"a":1}';
    $wrong = hash_hmac('sha256', $body, 'outro');

    expect((new VirtualPayWebhookVerifier('whsec_teste'))->verify($body, $wrong))->toBeFalse();
});

it('recusa quando o corpo foi adulterado', function (): void {
    $secret = 'whsec_teste';
    $signature = hash_hmac('sha256', '{"amount":"10.00"}', $secret);

    expect((new VirtualPayWebhookVerifier($secret))->verify('{"amount":"999.00"}', $signature))->toBeFalse();
});

it('fail-closed: secret vazio recusa tudo', function (): void {
    $body = '{"a":1}';
    expect((new VirtualPayWebhookVerifier(''))->verify($body, hash_hmac('sha256', $body, '')))->toBeFalse();
});
```

- [ ] **Step 2: Ver falhar; implementar; ver passar**

```php
<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Webhooks;

use Modules\Pagamentos\Contracts\WebhookVerifier;

/**
 * Verifies VirtualPay's X-Gateway-Signature: an HMAC-SHA256, hex-encoded, over
 * the raw request body, keyed by the webhook secret.
 *
 * The raw body must be the exact bytes received (Laravel's $request->getContent()),
 * never a re-encoded array — re-encoding reorders/repacks and breaks the HMAC.
 * Fail-closed: with no secret configured, every payload is rejected, because an
 * unverified payment webhook is the number-one fraud hole (the legacy had it).
 */
final class VirtualPayWebhookVerifier implements WebhookVerifier
{
    public function __construct(private readonly string $secret) {}

    public function verify(string $rawBody, string $signature): bool
    {
        if ($this->secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $this->secret), $signature);
    }
}
```

- [ ] **Step 3: Teste de confirmação contra a amostra real (opt-in)** — este é o que **prova** que o esquema medido bate com a VirtualPay. Roda só com o secret real em `VIRTUALPAY_WEBHOOK_SECRET`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier;

beforeEach(function (): void {
    $secret = Config::string('services.virtualpay.webhook_secret');
    if ($secret === '') {
        $this->markTestSkipped('Defina VIRTUALPAY_WEBHOOK_SECRET com o segredo real para confirmar o esquema.');
    }
    $this->secret = $secret;
});

it('reproduz a assinatura do webhook real registrado no log', function (): void {
    // Corpo cru compacto exato da amostra real (Content-Length 403).
    $body = '{"id":"8d8dc76b-aa39-4584-b25b-2d4984e6af29","data":{"fee":"0.60","type":"deposit","amount":"10.00","e2e_id":"E20855875202608050828L99D6YB6MTG","status":"paid","currency":"BRL","reference":"RECHARGE-9-1785918510","net_amount":"9.40","description":"Recarga de saldo - Fenix Center","transaction_id":"50a45b82-3d77-48d8-a4db-5c0263303402"},"event":"deposit.paid","occurred_at":"2026-08-05T05:29:02-03:00"}';
    $realSignature = '7d9b5e154565fe48953bc614f6e751207cc68477815c3a0e0f3ad149929908fa';

    expect((new VirtualPayWebhookVerifier($this->secret))->verify($body, $realSignature))->toBeTrue();
});
```

> Se este teste **falhar** com o secret real, o material assinado não é o corpo cru puro (talvez inclua um header ou timestamp). Nesse caso, ajustar `verify()` conforme o que a doc/o suporte da VirtualPay disсерem, e re-rodar contra a amostra até bater. O round-trip offline (Step 1) continua verde independentemente.

- [ ] **Step 4: Commit** — `feat(pagamentos): verificação de assinatura do webhook VirtualPay`

---

### Task 4: VirtualPayGateway (POST /transactions)

**Files:**
- Create: `modules/Pagamentos/Gateways/VirtualPayGateway.php`, `modules/Pagamentos/Exceptions/PaymentGatewayException.php`
- Test: `modules/Pagamentos/tests/Feature/VirtualPayGatewayTest.php` (com `Http::fake`)
- Test (opt-in): `modules/Pagamentos/tests/Feature/VirtualPayIntegrationTest.php`

**Interfaces:**
- Consumes: `PaymentGateway`, `GatewayCharge`, `PayerData` (Task 2).
- Produces: `VirtualPayGateway implements PaymentGateway` — construtor `(string $baseUrl, string $clientId, string $clientSecret)`. `createPixCharge(int $amountCents, string $reference, PayerData $payer): GatewayCharge` faz `Http::withHeaders(['x-client-id'=>…,'x-client-secret'=>…])->acceptJson()->post("$baseUrl/transactions", [...])`; converte centavos→reais (`number_format($amountCents/100, 2, '.', '')`); em erro (`$response->failed()`) lança `PaymentGatewayException`; no sucesso devolve `GatewayCharge::from($json['id'], $json['copy_paste'], $json['qr_code'] ?? null, $json['status'] ?? 'pending')`.

- [ ] **Step 1: Teste com Http::fake (falha primeiro)**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Pagamentos\Exceptions\PaymentGatewayException;
use Modules\Pagamentos\Gateways\VirtualPayGateway;
use Modules\Pagamentos\Support\PayerData;

function gateway(): VirtualPayGateway
{
    return new VirtualPayGateway('https://api.virtualpay.online/v2', 'cid', 'csecret');
}

it('cria a cobrança Pix e mapeia a resposta', function (): void {
    Http::fake(['*/transactions' => Http::response([
        'id' => 'uuid-1', 'copy_paste' => '000201...', 'qr_code' => 'base64==', 'status' => 'pending',
    ], 201)]);

    $charge = gateway()->createPixCharge(1000, 'RECHARGE-1-123', PayerData::for('Fulano', 'f@e.com'));

    expect($charge->externalId())->toBe('uuid-1')->and($charge->copyPaste())->toBe('000201...');

    Http::assertSent(function ($request) {
        return $request['type'] === 'deposit'
            && $request['payment_method'] === 'pix'
            && $request['amount'] === '10.00'          // centavos -> reais decimais
            && $request['reference'] === 'RECHARGE-1-123'
            && $request->hasHeader('x-client-secret', 'csecret');
    });
});

it('lança em erro do gateway', function (): void {
    Http::fake(['*/transactions' => Http::response(['message' => 'invalid'], 422)]);

    gateway()->createPixCharge(1000, 'RECHARGE-1-123', PayerData::for('Fulano', 'f@e.com'));
})->throws(PaymentGatewayException::class);
```

- [ ] **Step 2: Ver falhar; implementar gateway + exceção; ver passar**

`PaymentGatewayException extends RuntimeException` com `::from(int $status, string $message)`. Implementar o gateway conforme a interface acima. Rode o teste + Pint.

- [ ] **Step 3: Teste de integração real (opt-in)** — espelha `StripeIntegrationTest`: `beforeEach` faz `markTestSkipped` se `! Config::boolean('billing.virtualpay_integration')` ou `client_secret` vazio; o teste cria uma cobrança real de valor mínimo e afirma que veio `externalId`/`copyPaste`. Roda com `VIRTUALPAY_INTEGRATION=true`.

- [ ] **Step 4: Commit** — `feat(pagamentos): driver VirtualPay para criar cobrança Pix`

---

### Task 5: Enums + Model PaymentIntent

**Files:**
- Create: `modules/Pagamentos/Enums/PaymentStatus.php`, `modules/Pagamentos/Enums/PaymentPurpose.php`, `modules/Pagamentos/Models/PaymentIntent.php`, `modules/Pagamentos/Database/Factories/PaymentIntentFactory.php`, `modules/Pagamentos/database/migrations/*_create_payment_intents_table.php`
- Test: `modules/Pagamentos/tests/Feature/PaymentIntentTest.php`

**Interfaces:**
- Produces:
  - `enum PaymentStatus: string { Pending='pending'; Paid='paid'; Expired='expired'; Failed='failed'; }`
  - `enum PaymentPurpose: string { Recharge='recharge'; }` (plano entra na Fase D)
  - `PaymentIntent` (scoped): `id, team_id, reference (unique), external_id (nullable, indexed), amount_cents, status (enum, default pending), method ('pix'), purpose (enum), copy_paste (nullable), qr_code (nullable text), expires_at (nullable), paid_at (nullable), timestamps`. Casts enums + datetimes. `$fillable` (attribute) sem `team_id`. `markPaid(): void` (status Paid + paid_at now) — na Action, não no model? Manter model fino: transição fica na Action do webhook.

- [ ] **Step 1: Migration** — `payment_intents`: `team_id` FK cascade + index; `reference` string unique; `external_id` string nullable + index; `amount_cents` bigInteger; `status` string default 'pending'; `method` string default 'pix'; `purpose` string; `copy_paste` text nullable; `qr_code` text nullable; `expires_at` timestamp nullable; `paid_at` timestamp nullable; timestamps.

- [ ] **Step 2: Enums + factory + model** — seguir o padrão dos módulos Carteira/Consultas (`#[Fillable([...])]`, `#[Override] casts()`, `BelongsToTenant`, `@property`). Factory com `team_id => Team::factory()`, `reference` única, `amount_cents => 1000`, `status => Pending`, `purpose => Recharge`. States `paid()` e `withExternalId(string)`.

- [ ] **Step 3: Teste** — cria um PaymentIntent escopado; prova isolamento por time (team B não vê o de team A); confere casts de enum. Rode com `tests/Arch/ArchTest.php` (a Arch confirma a trait). Ver falhar antes.

- [ ] **Step 4: Commit** — `feat(pagamentos): PaymentIntent liga cobrança ao time`

---

### Task 6: Action CreateRecharge

**Files:**
- Create: `modules/Pagamentos/Actions/CreateRecharge.php`, `modules/Pagamentos/Exceptions/RechargeAmountException.php`
- Test: `modules/Pagamentos/tests/Feature/CreateRechargeTest.php` (com `Http::fake`)

**Interfaces:**
- Consumes: `PaymentGateway`, `PaymentIntent`, `PayerData`, `TenantManager`, `PaymentStatus`, `PaymentPurpose`.
- Produces: `CreateRecharge->handle(Team $team, int $amountCents, PayerData $payer): PaymentIntent` — valida `min_cents <= amount <= max_cents` (senão `RechargeAmountException`); dentro de `forTenant($team, ...)`: cria `PaymentIntent` pendente com `reference = 'RECHARGE-'.$team->id.'-'.<uuid/time>` e `expires_at = now()+expiration`; chama `$gateway->createPixCharge($amountCents, $reference, $payer)`; grava `external_id`, `copy_paste`, `qr_code`; devolve o intent. Se o gateway lançar, o intent pendente permanece (sem external_id) — pode ser expurgado depois; **não** credita nada (crédito só no webhook).

- [ ] Escrever teste (falha), implementar, ver passar, Pint, commit.
  Casos: valor válido cria intent pendente + guarda copy_paste/external_id + a `reference` casa; valor abaixo do mínimo e acima do máximo lançam `RechargeAmountException` sem chamar o gateway (`Http::assertNothingSent` no caminho inválido); o intent nasce `Pending` e **nenhum** crédito é lançado na carteira.
  Commit: `feat(pagamentos): action CreateRecharge cria a cobrança Pix`

---

### Task 7: Webhook — controller, rota, dedup, crédito

**Files:**
- Create: `app/Http/Controllers/VirtualPayWebhookController.php`
- Modify: `routes/web.php` (rota do webhook, fora do grupo `web`)
- Test: `tests/Feature/Pagamentos/VirtualPayWebhookTest.php`

**Interfaces:**
- Consumes: `WebhookVerifier` (bind), `PaymentIntent`, `PaymentStatus`, `Modules\Carteira\Actions\CreditWallet`, `WalletTransactionType`, `TenantManager`.
- Produces: `POST /webhooks/virtualpay` → `VirtualPayWebhookController::__invoke(Request)`.
  - Verifica `X-Gateway-Signature` sobre `$request->getContent()` (corpo cru) com o `WebhookVerifier` — inválida → **HTTP 401**, nada creditado.
  - Evento ∉ {`deposit.paid`,`transaction.updated`} ou `data.status !== 'paid'` → **HTTP 200**, ignora.
  - Acha o `PaymentIntent` por `external_id = data.transaction_id` **ou** `reference = data.reference`, via `PaymentIntent::acrossTenants()`. Não achou → **HTTP 200**, ignora (não é nosso).
  - **Idempotência:** dentro de `forTenant($intent->team)` + `DB::transaction`, trava o intent (`lockForUpdate`); se já `Paid` → **HTTP 200**, não credita de novo. Senão marca `Paid` + `paid_at`, e chama `CreditWallet::handle($intent->team, $intent->amount_cents, Recharge, "Recarga Pix — {$intent->reference}", $intent)`.
  - Sempre responde **200** para pagamento processado/ignorado; só a assinatura inválida dá **401**.

- [ ] **Step 1: Teste (falha primeiro)** — casos:
  1. `deposit.paid` com assinatura válida credita a carteira exatamente pelo `amount_cents` do intent e marca o intent `Paid`; a linha de razão referencia o intent.
  2. Reentrega do mesmo evento (mesmo intent já `Paid`) **não** credita de novo (saldo inalterado) e responde 200.
  3. Assinatura inválida → 401 e **nenhum** crédito, intent segue `Pending`.
  4. Evento desconhecido / `status != paid` → 200, sem crédito.
  5. `reference`/`transaction_id` desconhecido → 200, sem crédito.
  Todos os testes assinam o corpo com um `VIRTUALPAY_WEBHOOK_SECRET` de teste (via `config()->set`) e postam o corpo cru com o header `X-Gateway-Signature = hash_hmac('sha256', $body, $secret)`. O crédito é conferido pela carteira (Fase B). Ver falhar.

- [ ] **Step 2: Rota** — em `routes/web.php`, fora do grupo `web` (como a rota do webhook da Stripe), com comentário de que a assinatura é o que autentica:

```php
Route::post('webhooks/virtualpay', VirtualPayWebhookController::class)->name('webhooks.virtualpay');
```

- [ ] **Step 3: Controller** — implementar conforme a interface. Ler o corpo cru com `$request->getContent()` (não `$request->all()`), decodificar à parte para navegar. Verificação primeiro (fail-closed), depois dedup sob lock, depois crédito. Converter valor não é necessário (usa `amount_cents` do intent, não o do payload — o intent é a fonte da verdade do quanto foi cobrado; o payload confirma que foi pago).

> **Decisão registrada:** creditar `$intent->amount_cents` (o que cobramos), não `data.amount` do payload. Confiar no valor do payload deixaria um webhook forjado (se a assinatura vazasse) escolher o valor; o intent é o que nós emitimos. A assinatura protege a autenticidade; o intent protege o montante.

- [ ] **Step 4:** ver passar, Pint, commit: `feat(pagamentos): webhook assinado credita a carteira, idempotente`

---

### Task 8: Registrar bindings do módulo + verde total

**Files:**
- Modify: `modules/Pagamentos/Providers/PagamentosServiceProvider.php`
- Modify: `composer.json` (mutation classes)
- Test/gate: `composer check` (split host/Sail)

**Interfaces:**
- Produces: container resolve `PaymentGateway` → `VirtualPayGateway` (com config), `WebhookVerifier` → `VirtualPayWebhookVerifier` (com `services.virtualpay.webhook_secret`), ambos singletons.

- [ ] **Step 1: `registerModule()`** no provider:

```php
    #[\Override]
    protected function registerModule(): void
    {
        $this->app->singleton(\Modules\Pagamentos\Contracts\WebhookVerifier::class, fn () => new \Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier(
            \Illuminate\Support\Facades\Config::string('services.virtualpay.webhook_secret'),
        ));

        $this->app->singleton(\Modules\Pagamentos\Contracts\PaymentGateway::class, fn () => new \Modules\Pagamentos\Gateways\VirtualPayGateway(
            \Illuminate\Support\Facades\Config::string('services.virtualpay.base_url'),
            \Illuminate\Support\Facades\Config::string('services.virtualpay.client_id'),
            \Illuminate\Support\Facades\Config::string('services.virtualpay.client_secret'),
        ));
    }
```

Teste: `WebhookVerifier` e `PaymentGateway` resolvem para as implementações VirtualPay. (`config('services.virtualpay.client_id')` pode ser null em teste — use `Config::string` com default vazio via config; se o container reclamar de null, ajuste o cast na config para `(string) env(...)`.)

- [ ] **Step 2: Mutation** — em `composer.json` `test:mutate`, acrescentar à lista `--class=`: `Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier`, `Modules\Pagamentos\Actions\CreateRecharge`, `App\Http\Controllers\VirtualPayWebhookController`. Não rodar mutation agora.

- [ ] **Step 3: `composer check` partido**
  - Host: `vendor/bin/pint --dirty --format agent && composer refactor:check && composer spell:check && composer types:coverage`
  - Sail: `./vendor/bin/sail composer types:check && ./vendor/bin/sail artisan test --exclude-testsuite=Browser`
  - Prováveis: Peck (`pagamentos`, `virtualpay`, `webhook`? já ignorado?, `pix`, `hmac`) → adicionar termos genuínos a `peck.json`; Rector `composer refactor`; PHPStan (`->firstOrFail()` em lookups, tipos de `$request->json()`).

- [ ] **Step 4: Commit** — `chore(pagamentos): bindings, mutation e fecha composer check`

---

## Self-Review

**Spec coverage (D6, parte "adiciona" da Fase C):**
- Abstração `PaymentGateway` + driver VirtualPay → Tasks 2, 4. ✓
- Recarga = Pix avulso; `PaymentIntent` guarda QR/copia-e-cola/status/expiração/propósito → Tasks 5, 6. ✓
- Verificação de `X-Gateway-Signature` obrigatória, fail-closed → Task 3, 7. ✓
- Idempotência (não processar duas vezes) → Task 7 (lock + status Paid). ✓
- Crédito na carteira por recarga confirmada → Task 7 (`CreditWallet`, tipo Recharge). ✓
- Credenciais em `.env`/config, nunca em código → Task 1. ✓
- Teste de integração opt-in (como o da Stripe) → Task 4. ✓
- Confirmação do esquema de assinatura contra amostra real → Task 3 (opt-in). ✓

**Fora do escopo de Fase C (adiado, de propósito):** remoção do Cashier/Stripe → **Fase D** (decisão do usuário: remover antes de construir a assinatura substituta deixaria o produto sem billing de plano no intervalo); compra/renovação de plano via Pix e a máquina de estados da assinatura → **Fase D**; tela de recarga do usuário (QR na tela) → **Fase F**. O `CreateRecharge` e o `PaymentIntent` já são os pontos onde a Fase D pluga a compra de plano (mesmo mecanismo, `purpose` diferente).

**Dependência externa registrada honestamente:** o **valor** exato do webhook secret e a **confirmação final** do algoritmo vêm do painel da VirtualPay do usuário. O verificador é HMAC-SHA256 hex do corpo cru (medido: corpo cru é o material; client-secret **não** é a chave). O endpoint é **fail-closed** sem secret. O teste de amostra real (Task 3, opt-in) vira a prova assim que o secret real entrar em `VIRTUALPAY_WEBHOOK_SECRET`; se falhar, ajustar o material assinado e re-rodar — o round-trip offline não depende disso.

**Placeholder scan:** sem "TBD"/"TODO"; os passos com código trazem código real; as tasks 5/6/8 descrevem a forma exata (colunas, estados, assinaturas) seguindo o padrão já estabelecido nos módulos Carteira/Consultas.

**Type consistency:** `PaymentGateway::createPixCharge(int,string,PayerData): GatewayCharge`, `WebhookVerifier::verify(string,string): bool`, `CreateRecharge::handle(Team,int,PayerData): PaymentIntent`, `PaymentIntent`/`PaymentStatus`/`PaymentPurpose`, `GatewayCharge`/`PayerData` — consistentes entre definição (2-6) e consumo (7-8). `CreditWallet::handle(Team,int,WalletTransactionType,string,?Model)` da Fase B usado no webhook com `WalletTransactionType::Recharge`. `amount_cents` é a fonte da verdade do montante; a fronteira VirtualPay usa reais decimais só na conversão.
