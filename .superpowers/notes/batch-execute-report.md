# Executar consulta: input único → lote (batch)

## Status

Concluído. Suíte `ExecuteCheckScreenTest` verde (11/11), suíte não-Browser completa
verde, `pint`/`rector`/`phpstan`/`peck` limpos, `npm run build` ok. Commit criado em
`main` — ver SHA abaixo.

## Resumo dos testes

- `sail artisan test --compact tests/Feature/Product/ExecuteCheckScreenTest.php`:
  **11 passed, 39 assertions**.
- `sail artisan test --compact --exclude-testsuite=Browser` (suíte inteira):
  **715 total, 713 passed, 2 skipped** (skips pré-existentes, não relacionados).
- `vendor/bin/pint --dirty --format agent` (host): passou.
- `sail composer types:check` (phpstan): **0 erros**.
- `composer spell:check` (peck, host): passou — reescrevi duas palavras nos
  comentários do teste ("deduped" → "duplicates removed", `whitespace` no nome do
  teste → `blank_lines`) em vez de tocar em `peck.json`.
- `composer refactor:check` (rector, host): passou, 0 arquivos alterados.
- `npm run build`: ok.

## O que mudou

`resources/views/pages/checker/⚡execute.blade.php` — reescrito de ponta a ponta:

- `public string $document` → `public string $documents` (textarea).
- Removida a propriedade `#[Locked] public ?int $executionId`; no lugar,
  `#[Locked] public array $results = []` — uma entrada por linha submetida
  (`masked`, `status`, `execution_id`).
- `execute()` agora: reseta `$results`, valida `checkId`, checa disponibilidade do
  check, faz o parse do textarea (split por linha, trim, remove linhas em branco,
  `->unique()` preservando ordem, checa o teto de 100 linhas únicas), e roda um
  `resolve(ExecuteCheck::class)->handle(...)` por linha válida dentro de um
  `try/catch` **por linha** — uma linha ruim nunca aborta o lote.
- Nova constante `private const int MAX_BATCH = 100;`.
- Novo helper privado `mask()` que reaproveita `CheckInput::masked()` (nunca
  reimplementa a máscara).
- `execution()` + `costLabel()` (computeds de uma execução só) viraram
  `rows()` (mapeia `$results` para linhas prontas pra tela, buscando cada
  `CheckExecution` **escopada por `user_id`**, não só por time) e `summary()`
  (contagem para a barra de estatísticas). `costLabelFor()` é a mesma lógica de
  custo de antes, agora recebendo a execução em vez de ler de um computed.
- View: cartões de seleção do check mantidos como estavam. Único input trocado por
  `flux:textarea` (mono, `min-h-[150px]`, `wire:model="documents"`), com hint
  "CPF ou CNPJ, um por linha · até 100 por lote." Botão de gradiente de fogo mantido.
  Resultado agora é uma barra de estatísticas em linha (inspirada no
  `.checker-stats` do checker legado — Total / Sucesso / Sem registro / Falha /
  Não executadas) seguida de uma lista de linhas, cada uma com o documento
  mascarado, um badge de status (verde=sucesso, cinza=sem registro,
  vermelho=falha, âmbar=tudo que não rodou: inválido/bloqueado/saldo
  insuficiente/franquia esgotada/em manutenção) e, para sucesso, os campos do
  resultado + custo.

## Como o lote trata cada caso

- **Linha inválida** (não tem 11 nem 14 dígitos após limpar não-dígitos): marcada
  `invalid`, **nunca chega a rodar** `ExecuteCheck::handle()` — não cobra, não gera
  `CheckExecution`.
- **Duplicata**: linhas idênticas (após trim) colapsam em uma só antes do loop —
  rodam **uma vez**, preservando a ordem da primeira ocorrência.
- **Teto (101+ linhas únicas)**: recusado inteiro com `addError('documents', ...)`
  antes de rodar qualquer coisa — não trunca silenciosamente.
- **Erro no meio do lote** (`CheckUnavailableException`,
  `AllowanceExhaustedException`, `CheckBlockedForUserException`,
  `InsufficientBalanceException`): cada um vira uma entrada com o status
  correspondente e `execution_id: null`; o loop continua. Testado explicitamente
  para saldo insuficiente: reverti o catch de propósito, rodei o teste, vi ele
  falhar com a exceção vazando (mensagem "Saldo insuficiente: disponível 0, pedido
  500"), e restaurei o catch — o teste tem dentes.

## LGPD + escopo por dono

- O documento bruto nunca é armazenado nem exibido — só a forma mascarada
  (`CheckInput::masked()`, reaproveitada via o helper `mask()`). Testado
  (`test_the_raw_document_is_never_shown_only_the_masked_form`): a tela mostra o
  resultado (dado de terceiro) e a forma mascarada, mas `assertDontSee` o
  documento cru.
- `rows()` busca cada `CheckExecution` com `where('user_id', Auth::id())`, igual ao
  padrão já usado em `history.blade.php` — não basta escopo de time, precisa ser
  do dono. `results` é `#[Locked]`, então uma requisição forjada não consegue
  sequer injetar um `execution_id` de outra pessoa via `->set()`; o teste
  `test_a_team_member_cannot_view_a_teammates_batch_via_a_crafted_results_array`
  cobre os dois ângulos (Livewire recusa o `set()`, e se a trava sumisse um dia,
  o escopo por dono em `rows()` ainda recusaria).

## O que vale olhar no navegador

- A rolagem/leitura da lista de resultados com muitas linhas (ex.: 100) — não
  paginei nem virtualizei, é uma lista simples dentro de um card com
  `overflow-hidden` no container; vale ver se fica pesado visualmente com o lote
  cheio.
- O textarea em telas pequenas (mobile) — `min-h-[150px]` pode empurrar bastante
  conteúdo pra baixo do fold num paste grande.
- A cor âmbar (`flame-warn`) reaproveitada para cinco status diferentes
  (inválido/bloqueado/saldo insuficiente/franquia esgotada/manutenção) — a
  distinção fica só no texto do badge, vale confirmar que está legível.
