# Plataforma de consultas — plano assinado e carteira de créditos

**Data:** 2026-08-10 · **Estado:** aprovado, aguardando plano de implementação

## O que estamos construindo

Um SaaS onde a pessoa se cadastra e passa a consumir **consultas** — enriquecimento
cadastral (CPF/CNPJ) e score/crédito/antifraude, atendidas por integrações de
terceiros. Cada consulta se paga de duas formas, e o admin decide qual vale para
cada uma: **assinatura de plano** (com franquia mensal ou uso ilimitado das
consultas incluídas) ou **crédito** (carteira em reais, debitada por uso).

O boilerplate Laravel que envolve este documento já entrega o que está por baixo:
autenticação, multi-tenancy fail-closed, RBAC, painel Filament, filas/Horizon,
API, observabilidade e a suíte de qualidade. Este design é a camada de produto que
faltava — e **substitui** a fase de billing do boilerplate (Cashier/Stripe) por um
gateway nacional.

O projeto legado em `center/` **não entra**: é apenas referência visual (tema
escuro "fogo", sidebar, stat-cards). Nenhuma linha, tabela ou regra dele é
reaproveitada.

## Decisões fechadas (com o usuário)

| Eixo | Decisão | Por quê |
|---|---|---|
| Cliente | Pessoa individual (B2C) | Carteira e plano por conta; sem convites na UX |
| Crédito | Carteira em **reais** (centavos) | Cada consulta tem preço em R$; saldo é dinheiro |
| Cobrança do plano | Por consulta: **franquia mensal** ou **ilimitado** | O admin escolhe caso a caso |
| Integração | Consulta = **classe PHP** com contrato nosso | Controle, teste, versionamento, resiliência |
| Cobrança na falha | **Só debita no resultado útil** | Timeout / fora do ar / "nada encontrado" não cobram |
| Pagamento | Gateway **nacional** (virtualpay.online), **Pix avulso** | Remove Stripe/Cashier |
| Assinatura | **Ciclo pré-pago renovável** (sem recorrência no gateway) | A VirtualPay só faz Pix avulso — sem Pix Automático nem cobrança agendada |

## Vocabulário

O legado confundia o produto com a integração. Aqui eles são separados:

- **Consulta** — o item de catálogo que a pessoa vê e paga ("Consulta CPF
  Completa", "Score de Crédito"). Tem nome, categoria, preço, modo de cobrança e
  estado (ativa / em manutenção / inativa).
- **Provider (Fonte)** — a integração de terceiro que responde por trás. É a
  classe PHP. Uma consulta aponta para um provider.

Multi-tenancy é a premissa do boilerplate: o tenant é o **time**. Em B2C cada
pessoa tem um time pessoal invisível, e toda tabela de domínio nasce escopada por
`team_id`. A carteira e a assinatura moram no time.

## Arquitetura em módulos

Seguindo o sistema de módulos do projeto (`make:module`, fronteiras testadas pela
suíte `Arch`), o domínio novo se divide em três módulos e um ajuste na camada de
aplicação:

```
modules/
├── Consultas/   # catálogo, contrato de provider, resiliência, execução
├── Carteira/    # wallet, razão append-only, holds, política de cobrança
└── Pagamentos/  # abstração de gateway, driver VirtualPay, recarga, assinatura
```

Regra de dependência do projeto (verificada no CI): um módulo depende de `Core` e
de si mesmo, **nunca de um irmão**. A composição acontece em `app/`. Então:

- `Consultas` não sabe cobrar. Emite `ConsultaExecutada` / `ConsultaFalhou`.
- `Carteira` não sabe consultar. Expõe `reservar()`, `confirmar()`, `liberar()`.
- `Pagamentos` credita a carteira por evento, não por chamada direta.
- A **action de aplicação** `ExecutarConsulta` (em `app/Actions/Consultas/`) é o
  ponto de composição: reserva na carteira ou consome franquia, chama o provider,
  e confirma ou libera conforme o desfecho.

Um verbo, uma action, um `handle` — como o resto do projeto.

## D1 — O contrato do provider distingue três desfechos

```php
// Assinatura pretendida, não código final.
interface ProviderContract
{
    public function key(): string;
    public function execute(ConsultaInput $input): ProviderResult;
    public function healthCheck(): bool;
}
```

`ProviderResult` carrega um de três estados, e a distinção **é** a regra de
cobrança:

- `success` — veio dado útil. **Cobra.**
- `no_data` — a consulta rodou e não encontrou registro. **Não cobra** (decisão do
  usuário). É um desfecho legítimo, não um erro.
- `failed` — falha técnica (timeout, provider fora do ar, resposta inválida).
  **Não cobra** e alimenta o circuit breaker.

Provider novo = classe nova implementando o contrato + uma linha num **registro**
(`ProviderRegistry`), espelhando o padrão `ResourceCounters` que o projeto já usa.
O terceiro entrega a classe; nós commitamos. Nada de URL/headers configurados por
painel — o mapeamento de resposta de terceiro em configuração vira uma linguagem
de programação mal feita dentro do banco.

## D2 — Resiliência é um envelope, não responsabilidade do provider

Todo `execute` passa por um `ResilientProvider` que decora o provider real:

- **Timeout curto** por consulta (config no catálogo).
- **Retry com backoff** apenas para `failed`, nunca para `no_data`.
- **Circuit breaker** em cache: N falhas seguidas abrem o circuito e a consulta é
  marcada **"em manutenção" automaticamente** — para de ser oferecida na tela sem
  derrubar nada. O circuito meio-aberto testa a recuperação.
- **Flag de manutenção manual** no admin, independente do breaker.

O provider de terceiro escreve só a chamada e o parsing. Timeout, retry e breaker
são do envelope, iguais para todos — senão cada terceiro reimplementa resiliência
do seu jeito e a gente herda os bugs.

**Execução síncrona no MVP.** A pessoa espera o resultado; a consulta roda na
requisição com timeout apertado. Consultas lentas viram job com progresso por
Reverb numa fase posterior — a infra de tempo real já existe, mas não é fundação.

## D3 — Carteira: razão append-only com reserva

- **`Wallet`** por time: saldo em centavos.
- **`WalletTransaction`** append-only: `recharge`, `charge`, `refund`, `bonus`,
  `adjustment`. Cada linha grava o `balance_after`. **Nunca se edita uma linha** —
  corrigir é lançar outra. O saldo é a soma do razão, e a coluna em `Wallet` é
  cache reconciliável.

**Reserva (hold) resolve concorrência sem trair o "só cobra no sucesso".** Como só
debitamos no desfecho e a consulta demora, dois cliques simultâneos poderiam
receber dado com saldo para um só. Então:

1. `reservar(valor)` — cria hold; saldo disponível = saldo − holds ativos. Se o
   disponível não cobre, recusa **antes** de chamar o provider.
2. `execute` roda.
3. `success` → `confirmar(hold)`: o hold vira `charge` no razão.
4. `no_data` / `failed` → `liberar(hold)`: hold descartado, nenhum débito.

O hold expira sozinho (job) para o caso de o processo morrer entre reservar e
confirmar — hold órfão que não expira é saldo preso para sempre.

**Aresta conhecida (implementada na Fase B, a fechar quando a execução virar
assíncrona):** se o provider responde com sucesso mas o `confirmar(hold)` falha
no commit — deadlock — ou se o hold expira no meio de uma chamada longa, a
execução é gravada como `success` e o hold é solto. O resultado é **cobrar a
menos, nunca a mais**, e o saldo nunca fica negativo; a execução continua
auditável. Com execução síncrona e timeout curto isso é raríssimo. Quando a
execução passar para job com progresso (fora do escopo de A/B), o confirmar deve
acontecer na mesma unidade de trabalho que registra o sucesso, ou o sucesso só
ser gravado depois do débito confirmado.

> A reserva é o coração da correção do sistema. Precisa ser vista falhar: remover
> o `FOR UPDATE` da leitura de saldo, disparar duas execuções concorrentes com
> saldo para uma, e provar que a suíte acusa saldo negativo.

## D4 — Plano, franquia e o invariante "contador primeiro"

Uma consulta coberta por plano ativo consome **franquia** (X/mês) ou é
**ilimitada**. A ligação plano ↔ consulta vive em tabela (`plan_consultas`:
`plan`, `consulta_id`, `quota` nullable = ilimitado), porque o catálogo é dinâmico
— admin cria consulta — e não caberia em `config/billing.php`.

Mantém-se o invariante do projeto: **contador primeiro, limite depois**. Cada
consulta com franquia registra um contador no padrão `ResourceCounters`; um teste
varre as franquias e falha se alguma nomeia consulta sem contador. Franquia sem
contador é teto que nunca se aplica.

Ordem de resolução na execução:

1. Consulta incluída no plano e **ilimitada** → executa, não toca carteira.
2. Incluída com **franquia** e dentro do período → incrementa contador no sucesso,
   não toca carteira.
3. Franquia esgotada → cai para crédito **se** a consulta aceitar ambos os modos;
   senão bloqueia com mensagem que nomeia o caminho (recarregar ou subir de plano).
4. Sem plano cobrindo → crédito, pela regra da reserva.

O incremento do contador de franquia acontece no **mesmo desfecho** que o débito:
só no `success`. `no_data` não consome franquia.

**Aresta conhecida:** o uso da franquia é derivado do histórico de
`CheckExecution` no período, contado na hora, sem lock. Duas requisições
simultâneas perto do teto podem passar juntas e estourar a franquia em 1.
Aceito para o MVP, e sempre a favor do cliente: para um check só com franquia,
é uma execução incluída a mais; para um check com franquia **e** preço em
crédito, é uma execução dada de graça que seria debitada — ou seja, uma
**subcobrança limitada**, nunca uma cobrança a mais. Por isso o tratamento de
concorrência com `FOR UPDATE`/hold da carteira (D3) não se aplica: o pior caso
aqui não tira dinheiro de ninguém.

## D5 — Gestão particular por usuário

O admin gerencia caso a caso sem tocar no catálogo geral. Tabela de override
(escopada, por usuário/time + consulta):

- **preço custom** de uma consulta para aquele usuário;
- **liberar** uma consulta específica fora do plano dele;
- **bloquear** uma consulta para aquele usuário.

A resolução de preço/acesso consulta o override antes do catálogo. Override é a
exceção nomeada, com registro de quem concedeu — o mesmo espírito do
`Feature::for($team)->activate()` que o Pennant já dá.

## D6 — Pagamentos nacionais, e o que sai do boilerplate

Este é o maior desvio do que já está pronto. O boilerplate construiu billing com
**Cashier/Stripe**. O produto usa **só VirtualPay**, e a API dela — conferida na
integração do legado — faz **uma coisa só**: cobrança Pix avulsa.

**O que a VirtualPay oferece de fato** (`api.virtualpay.online/v2`):

- Autenticação por headers `x-client-id` + `x-client-secret`.
- `POST /transactions` com `type: deposit, payment_method: pix, amount, reference,
  email, name, document, phone` → devolve `{ id, copy_paste, qr_code, status }`.
- Webhook nos eventos `deposit.paid` / `transaction.updated` com `status: paid` e
  header `X-Gateway-Signature`.
- **Não há** assinatura recorrente, Pix Automático, cobrança agendada nem cartão
  nesta integração. Todo pagamento é um Pix único que a pessoa paga na hora.

Desenho:

- **Abstração `PaymentGateway`** (contrato) com **driver VirtualPay**. Como o
  gateway só faz uma operação, o contrato é enxuto: `criarCobrancaPix(valor,
  referencia, cliente): CobrancaPix` e a verificação do webhook. A regra de
  arquitetura impede o driver de vazar para fora do módulo `Pagamentos`.
- **Recarga e compra de plano usam o mesmo mecanismo** — um Pix avulso — e se
  distinguem pela `referencia` (`RECHARGE-<team>-<ts>` vs `PLAN-<slug>-<team>-<ts>`).
  Uma integração, dois propósitos. `PaymentIntent` guarda QR, copia-e-cola, status,
  expiração e o propósito.
- **Verificação de `X-Gateway-Signature` é obrigatória** e é o que autentica a
  chamada — o endpoint não tem sessão. O legado não verificava nada e tinha um
  fallback que creditava a recarga pendente mais recente quando não achava a
  transação; isso é a porta de fraude nº 1 dele e **não** se repete aqui.
  Idempotência pelo `id` da transação para o gateway reentregar sem processar duas
  vezes.
- **Esquema da assinatura — confirmado no código-fonte do gateway** (fase C+):
  `hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $secret)`,
  hex, via Spatie WebhookServer (`GatewayWebhookSigner`). Esse `json_encode` **é**
  o corpo cru que o gateway envia, então o verificador só precisa aplicar
  `hash_hmac('sha256', $corpoCru, $secret)` sobre `$request->getContent()` — nunca
  sobre um array re-serializado (a ordem/escape dos flags mudaria e a assinatura
  quebraria). O `$secret` é um **segredo por webhook** que o dono do gateway
  **digita** ao cadastrar o endpoint (`merchant_webhooks.secret`, texto puro, sem
  geração automática nem API para buscá-lo) — os dois lados usam o mesmo valor.
  Em dev local, o comando `php artisan virtualpay:webhook-secret <valor>` troca o
  segredo no `.env` sem edição manual. O evento real é `deposit.paid`; `amount`
  vem em reais decimais, mas o crédito usa o `amount_cents` do `PaymentIntent`, não
  o do payload.
- **Credenciais em `.env` / cifradas, nunca em código.** O legado deixa
  `client-id` e `client-secret` embutidos num arquivo versionável — as do legado
  precisam ser **rotacionadas** antes de qualquer uso, porque já vazaram.

**Assinatura é um ciclo pré-pago renovável, gerido pela aplicação — não pelo
gateway.** Sem cobrança recorrente, a assinatura é um período pago à frente:

- Comprar plano = um Pix avulso pelo preço do plano → o webhook confirmado
  **ativa** o plano por N dias (`current_period_end`). A franquia mensal zera a
  cada ciclo pago.
- **Perto do vencimento**: notificação + botão "renovar" que gera novo Pix. Nada é
  cobrado automaticamente; a pessoa paga cada ciclo ativamente.
- **Não pagou até o fim do período** (mais uma carência configurável): a assinatura
  **expira** e o time cai no `default_plan` (gratuito). Sem `default_plan`, "sem
  assinatura" viraria "sem limite".
- A máquina de estados **simplifica** em relação ao boilerplate: `ativa →
  (vencendo) → expirada`. **Não há dunning por retentativa** — não existe cobrança
  automática para falhar e reagendar. O conceito de estado do `TeamBilling` é
  reaproveitado; a origem passa a ser um model `Subscription` nosso.
- Suspensão/expiração nunca bloqueia a tela onde se renova.

- **Cashier/Stripe é removido** (`docs/poda.md` existe para isso), seguindo a
  aversão do projeto a duas fontes de verdade para cobrança. A migração de colunas
  do Cashier em `teams` sai junto.

> O primeiro teste de integração contra a VirtualPay é **opt-in** (variável de
> ambiente), como o `StripeIntegrationTest` já era: suíte que chama gateway em
> silêncio quebra quando a rede quebra. A verificação de assinatura do webhook, ao
> contrário, roda offline — o algoritmo é conhecido e o segredo é nosso.

## D7 — Admin (Filament, em `/admin`)

O painel é do operador e só existe em `/admin` (ADR 0003). Resources:

- **Consultas** — catálogo, preço, modo de cobrança, toggle de manutenção.
- **Providers** — saúde e estado do circuit breaker, healthcheck manual.
- **Planos** — inclusão de consultas + franquia/ilimitado.
- **Usuários** — overrides, crédito manual (vira `adjustment` no razão), preço
  custom, bloqueio.
- **Carteira / Razão** — leitura do razão de um time (append-only, sem editar).
- **Recargas / Pagamentos** e **Assinaturas** — estado e histórico.
- **Execuções** — auditoria das consultas rodadas (sem PII crua — ver D9).
- **Relatórios** — receita, MRR, consultas/dia, **taxa de sucesso por provider**,
  saldo agregado.

Como no resto do painel: usuário e time são leitura e ação, não edição livre; a
trilha de auditoria não tem criar/editar/apagar.

## D8 — Frontend (Livewire 4 + Flux, migrando o layout do legado)

O sistema visual "fogo" do legado é redesenhado para Flux + Tailwind v4 (a skill
`frontend-design` guia a implementação; assume-se Flux **free** e confere-se antes
de qualquer variante Pro). Telas:

- **Dashboard** — stat-cards (consultas, sucesso, saldo) + timer de plano.
- **Executar consulta** — no lugar do "checker": escolha da consulta, formulário
  de entrada validado, resultado, custo. Estado de "em manutenção" visível.
- **Carteira / Recarga** — saldo, gerar Pix (QR + copia-e-cola), histórico do
  razão.
- **Planos** — agora **self-service** via VirtualPay (o legado mandava falar no
  Telegram).
- **Perfil** e **Histórico** de consultas.

App do usuário é 100% Livewire+Flux; a estética do Filament não vaza para o
produto (ADR 0003).

## D9 — LGPD: dado pessoal é o material desta consulta

Resultado de consulta contém dado de terceiro. Tratamento com peso:

- **Entrada mascarada** nas listagens (CPF parcial); nunca logar PII crua.
- **Resultado bruto cifrado** em repouso, com **TTL de retenção** configurável e
  **job de expurgo** — reaproveita o padrão `users:purge` que já existe.
- **Execução guarda o mínimo**: quem consultou, qual consulta, desfecho, custo,
  duração. O payload sensível é separado, cifrado e expira.
- O `team_id` acompanha tudo (o escopo fail-closed já garante), e o `request_id`
  entra no contexto de observabilidade que o projeto já publica.
- **Quando o primeiro provider HTTP real entrar** (fase posterior à A): o
  `failure_reason` da execução guarda o texto cru da exceção do provider, numa
  coluna não cifrada. Mensagem de erro de terceiro pode carregar a entrada crua
  (um CPF na URL da requisição, por exemplo) para dentro desse campo — o mesmo
  vazamento que esta seção evita. O ponto de mascarar/filtrar é a fronteira em
  `ResilientProvider::attempt()`, onde a exceção vira `ProviderResult::failed()`.
  Em Fase A os providers são falsos e nada de PII flui por ali; a guarda entra
  junto com o provider real.

## Ordem de construção

Cada fase tem aceite verde (`composer check`) antes de avançar. Cada uma ganha
plano de implementação próprio.

- **A — Módulo `Consultas` (núcleo).** Contrato, registro, `ResilientProvider`,
  circuit breaker, catálogo, execução, manutenção. Provider **falso** para teste.
  Ainda sem cobrar — roda e registra.
- **B — Módulo `Carteira`.** Wallet, razão append-only, holds com expiração,
  política charge-on-success plugada na action `ExecutarConsulta`. Crédito manual
  do admin.
- **C — Módulo `Pagamentos` (VirtualPay).** Abstração, recarga Pix, webhook
  assinado e idempotente, crédito na carteira por evento. **Só adiciona** — a
  remoção do Cashier/Stripe foi movida para a Fase D (decisão do usuário: tirar a
  cobrança de plano antes de construir a substituta deixaria o produto sem billing
  de plano no intervalo).
- **D — Planos e assinatura.** `plan_consultas`, franquia + contadores, gating,
  assinatura como ciclo pré-pago renovável (compra e renovação de plano via Pix
  avulso), estados, overrides por usuário. **Remove Cashier/Stripe** aqui, junto
  com a assinatura pré-paga que o substitui.

  **Status:** a Fase D-a (assinatura pré-paga via Pix + remoção do Cashier) e a
  Fase D-b (franquia de plano por check) estão **concluídas**. Os overrides por
  usuário seguem em D-c.
- **E — Admin Filament.** Resources, relatórios, saúde de provider, gestão por
  usuário.
- **F — Frontend.** Migração/redesign do layout, telas do usuário, LGPD na tela.

A ordem não é arbitrária: a carteira precisa do motor de consulta para ter o que
cobrar; o pagamento precisa da carteira para ter o que creditar; o plano precisa
dos dois para escolher entre franquia e crédito; o admin e o frontend precisam do
domínio pronto para ter o que mostrar.

## Fora de escopo (deliberado)

- **Execução assíncrona com progresso em tempo real** — só quando houver consulta
  comprovadamente lenta. A infra Reverb existe; o gancho fica.
- **Provider primário + fallback** — o contrato já permite; a orquestração de
  fallback é fase posterior, quando houver dois providers para a mesma consulta.
- **Conta de organização (B2B)** — decidido B2C. Times/convites/RBAC continuam por
  baixo, mas a UX não os expõe.
- **Cobrança por assento** — não há assento; o plano é preço fixo + franquia/crédito.

## Riscos

| Risco | Impacto | Mitigação |
|---|---|---|
| Reserva de saldo incorreta sob concorrência | **Crítico** | `FOR UPDATE` + hold; teste de corrida visto falhar; fase B orça mais teste que código |
| Webhook de pagamento sem verificação | **Crítico** | `X-Gateway-Signature` obrigatória + idempotência; é o erro exato do legado |
| Credenciais VirtualPay do legado vazadas | **Alto** | Rotacionar antes de usar; no novo sistema em `.env`/cifradas, nunca em código |
| Vazamento de PII de consulta | **Alto** | Cifra em repouso, TTL, expurgo, mascaramento; escopo fail-closed já ativo |
| Sem recorrência automática — cliente esquece de renovar | **Médio** | Ciclo pré-pago com lembrete antes do vencimento + carência antes de cair no gratuito |
| Remover Cashier quebra o que a suíte cobre | **Médio** | `docs/poda.md` guia a remoção; `composer check` depois de cada corte |
| Circuit breaker esconder consulta boa | **Baixo** | Meio-aberto testa recuperação; toggle manual no admin; métrica de sucesso por provider no relatório |

## Verificação

Vale o padrão do projeto: **todo teste novo é visto falhar antes de valer**.
`tests/Feature` para quase tudo, `tests/Unit` para o que é puro (Luhn de CPF,
soma do razão), `tests/Arch` para a fronteira entre módulos, `tests/Browser` para
o fluxo de executar consulta com JS. Mutation testing entra em reserva de saldo,
política de cobrança e verificação de webhook — as três invariantes que, se
quebrarem em silêncio, custam dinheiro.
