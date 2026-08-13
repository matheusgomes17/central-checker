# Executar consulta: input único → lote (batch)

## Status

Concluído, incluindo a rodada de revisão (1 Critical LGPD + 1 Important + 2 Minor,
todos corrigidos). Suíte `ExecuteCheckScreenTest` verde (16/16), suíte não-Browser
completa verde, `pint`/`rector`/`phpstan`/`peck` limpos, `npm run build` ok. Commits
em `main` — ver SHAs abaixo.

## Resumo dos testes

- `sail artisan test --compact tests/Feature/Product/ExecuteCheckScreenTest.php`:
  **16 passed, 56 assertions** (11 da primeira rodada + 5 da revisão).
- `sail artisan test --compact --exclude-testsuite=Browser` (suíte inteira, antes e
  depois da revisão): **verde, sem regressão** (2 skips pré-existentes, não
  relacionados).
- `vendor/bin/pint --dirty --format agent` (host): passou.
- `sail composer types:check` (phpstan): **0 erros**.
- `composer spell:check` (peck, host): passou.
- `composer refactor:check` (rector, host): passou, 0 arquivos alterados.
- `npm run build`: ok.

## O que mudou (primeira rodada)

`resources/views/pages/checker/⚡execute.blade.php` — reescrito de ponta a ponta:

- `public string $document` → `public string $documents` (textarea).
- Removida a propriedade `#[Locked] public ?int $executionId`; no lugar,
  `#[Locked] public array $results = []` — uma entrada por linha submetida
  (`masked`, `status`, `execution_id`).
- `execute()`: reseta `$results`, valida `checkId`, checa disponibilidade do check,
  faz o parse do textarea (split por linha, trim, remove linhas em branco,
  `->unique()` preservando ordem, checa o teto de 100 linhas únicas), e roda um
  `resolve(ExecuteCheck::class)->handle(...)` por linha válida dentro de um
  `try/catch` **por linha** — uma linha ruim nunca aborta o lote.
- Nova constante `private const int MAX_BATCH = 100;`.
- `rows()` (mapeia `$results` para linhas prontas pra tela) e `summary()` (contagem
  para a barra de estatísticas) substituem os antigos `execution()`/`costLabel()` de
  uma execução só.
- View: cartões de seleção do check mantidos. Único input trocado por
  `flux:textarea` (mono, `min-h-[150px]`, `wire:model="documents"`). Resultado agora
  é uma barra de estatísticas em linha (inspirada no `.checker-stats` do checker
  legado) seguida de uma lista por linha com badge de status.

## Correções da revisão

### Critical (LGPD) — linha inválida curta vazava o valor cru

`mask($line)` delegava pra `CheckInput::masked()`, que **por design** devolve
valores curtos (< 6 chars) sem mascarar — seguro pro uso normal dele (um CPF/CNPJ já
validado, sempre ≥ 11 dígitos), mas não pra uma linha `invalid`, cujo texto bruto
nunca passou por aquele filtro de comprimento. Uma linha inválida curta ("1234", "5")
era gravada em `$results` e **renderizada verbatim**.

Correção: novo helper `hardMask()` — sempre obscurece, mantém no máximo o primeiro
caractere, estrela tudo em strings de 1–2 chars. Aplicado só no ramo `invalid`; o
ramo válido continua com `mask()` (sempre ≥ 11 dígitos, seguro).

**Visto falhar primeiro:** revertido `hardMask($line)` para `mask($line)` no ramo
inválido e rodado `test_a_short_invalid_line_never_leaks_the_raw_value` — falhou
porque o HTML renderizado continha `data-test="linha-documento-0">1234<` (o "1234"
cru, verbatim). Corrigido e reconfirmado verde.

### Important — owner-scope de `rows()` sem cobertura própria

O teste original só provava o `#[Locked]` (Livewire recusa o `->set('results', ...)`
antes de `rows()` rodar) — o `where('user_id', Auth::id())` dentro de `rows()` nunca
era exercido. Novo teste
`test_rows_scopes_execution_lookup_to_the_owner_even_if_locked_is_bypassed` contorna
o Locked via `ReflectionProperty` (escreve `results` direto na instância) e chama
`rows()` via `ReflectionMethod` (mesmo motivo: método só existe na classe anônima do
Volt, invisível pro phpstan), com um `execution_id` de um colega de time — prova que
o resultado (decifrado) do colega não vaza mesmo com a trava contornada.

**Visto falhar primeiro:** removida a cláusula `where('user_id', ...)` de `rows()` e
rodado o teste — falhou com `Failed asserting that Array &0 ['nome' => 'Fulano de
Tal'] is null` (o resultado do colega vazando). Corrigido e reconfirmado verde.

### Minor 1 — N+1 em `rows()`

Trocado o `CheckExecution::query()->find($id)` por linha por **uma única query**:
coleta os `execution_id` não nulos do lote inteiro, `whereIn('id', $ids)` (ainda
escopado por `user_id`), `->get()->keyBy('id')`, e mapeia. Um lote de 100 vira 1
SELECT em vez de até 100.

### Minor 2 — cobertura das 4 exceções por linha

Antes só `InsufficientBalanceException` tinha teste dedicado. Adicionados:

- `test_allowance_exhausted_partway_marks_the_rest_without_aborting_the_batch` —
  `PlanCheck` com quota 1, check grátis (sem preço de fallback): a 1ª linha consome a
  franquia, a 2ª bate em `AllowanceExhaustedException`.
- `test_a_blocked_user_marks_every_line_without_aborting_the_batch` —
  `UserCheckOverride::factory()->blocked()`: toda linha bate em
  `CheckBlockedForUserException` antes de chamar o provider.
- `test_check_unavailable_mid_batch_marks_the_rest_without_aborting_the_batch` —
  cenário mais elaborado: 4 linhas com o FakeProvider sempre devolvendo `failed`
  (2 tentativas cada, via `ResilientProvider::MAX_TRIES`); a 3ª linha faz o total de
  falhas bater o limiar do `CircuitBreaker` (5) e o circuito abre; a 4ª linha nem
  chama o provider — `ExecuteCheck::handle()` recusa direto com
  `CheckUnavailableException` na sua própria guarda de disponibilidade. Usa
  `Sleep::fake()` pra não pagar o backoff real do `ResilientProvider` entre
  tentativas.

## Como o lote trata cada caso (atualizado)

- **Linha inválida**: marcada `invalid` com `hardMask()` (nunca `mask()`) —
  nunca chega a rodar, não cobra, não gera `CheckExecution`.
- **Duplicata**: linhas idênticas (após trim) colapsam em uma só antes do loop.
- **Teto (101+ linhas únicas)**: recusado inteiro, não trunca.
- **Erro no meio do lote** (as 4 exceções de domínio, agora todas com teste
  dedicado): cada uma vira uma entrada com o status certo, `execution_id: null`, o
  loop continua.

## LGPD + escopo por dono

- Documento bruto nunca armazenado nem exibido — linhas válidas via `mask()`
  (`CheckInput::masked()`), linhas inválidas via `hardMask()` (sempre obscurece,
  independente do comprimento).
- `rows()` busca os `CheckExecution` do lote **escopados por `user_id`**, numa única
  query. `results` é `#[Locked]`. Os dois ângulos têm teste próprio agora: o Locked
  em si, e o `where('user_id')` isolado (contornando o Locked via reflection).

## O que vale olhar no navegador

- A rolagem/leitura da lista de resultados com muitas linhas (ex.: 100) — sem
  paginação/virtualização.
- O textarea em telas pequenas (mobile) — `min-h-[150px]` pode empurrar bastante
  conteúdo pra baixo do fold.
- A cor âmbar (`flame-warn`) reaproveitada para cinco status diferentes — a
  distinção fica só no texto do badge.
