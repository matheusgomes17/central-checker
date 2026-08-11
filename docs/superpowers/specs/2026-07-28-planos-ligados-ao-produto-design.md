# Ligar o plano ao produto

**Data:** 2026-07-28 · **Estado:** aprovado, aguardando plano de implementação

## O problema

A máquina de cobrar por recurso está inteira e não está plugada em nada.

| Vendido em `config/billing.php` | O que acontece de verdade |
|---|---|
| `api` no Pro e no Business | A API v1 não pergunta o plano. Time no gratuito cria token e usa tudo. |
| `audit-trail` no Pro e no Business | A trilha existe e mora no `/admin`, que é do operador. Quem paga não tem tela. |
| `sso` no Business | Não existe uma linha. |
| Limites `projects` e `storage_mb` nos três planos | `TeamBilling::countOf()` só sabe contar membros e devolve `0` para o resto. |
| Limite `members` nos três planos | **Não é verificado em lugar nenhum.** |

Duas peças confirmam o diagnóstico sem depender de leitura: `EnsureWithinPlanLimits`
e `PlanLimitException` aparecem com **0% de cobertura** no relatório da suíte —
não porque falte teste, mas porque nada os executa. E `Feature::active()` do
Pennant não é chamado em nenhum lugar da aplicação, embora `PlanFeature::registerAll()`
defina uma feature por capacidade de plano.

O middleware `EnsureWithinPlanLimits` está registrado como alias `limits` em
`bootstrap/app.php` e **nenhuma rota o usa**. O docblock dele afirma o contrário:
"Applied to the routes that add something countable". É documentação descrevendo
algo que não acontece.

### Por que isso vem antes da camada de adquirência

O trabalho maior em discussão é uma camada que abstraia adquirências (Stripe e
Pagar.me, com capacidades assimétricas). Abstrair o provedor de pagamento de um
produto que ainda não cobra por nada é otimizar a parte que não decide. Esta
fase é pré-requisito: sem ela, rotear entre dois provedores vende planos cujos
recursos ninguém verifica.

## Escopo

**Entra:** verificação de limite no lugar certo; contadores como registro
extensível; `api` como recurso verificado; catálogo de planos parando de
anunciar o que não existe; política e visibilidade de quem está acima do teto.

**Não entra:**

- **Tela de trilha para o cliente.** `audit-trail` sai do catálogo e volta junto
  com a tela. Superfície de produto nova não pertence a uma fase sobre honestidade.
- **SSO.** Sai do catálogo. Implementar é projeto próprio, de semanas.
- **Troca de plano com proporção e listagem de faturas.** São a fase seguinte.
- **Cobrança por assento ou por uso.** O plano continua sendo preço fixo com teto.

## Decisões

### D1 — O limite é verificado na action, não em middleware

Convidar pela tela acontece dentro de um componente Livewire, que trafega pela
rota `livewire.update`. Middleware de rota nunca cobre isso — é por isso que o
alias `limits` nunca foi aplicado, e não foi esquecimento.

As duas portas já convergem: `⚡invite-member-modal` e `Api\V1\InvitationController`
chamam `InviteTeamMember::handle()`. `ImportInvitations` é a terceira e hoje não
olha teto nenhum.

`EnsureWithinPlanLimits`, o alias `limits` e o teste que o cobrir saem. Verificar
nos dois lugares seria a duplicação de fonte de verdade que o
[ADR 0006](../../adr/0006-papeis-por-time-e-super-admin-fora.md) registrou
custando caro com papéis.

### D2 — O contador soma convites pendentes

`countOf('members')` conta membership. Um time em 2 de 3 manda 50 convites e
termina com 52 membros quando todos aceitarem: o teto é contornável sem tentar.
O contador passa a somar **membros mais convites pendentes não expirados**.

### D3 — Importação em lote é recusada inteira

Não é "cabe mais um", é "cabem mais N" — e `withinLimit($resource, $adding)` já
aceita a quantidade. Um lote que estoura é recusado por inteiro, com a conta na
mensagem ("o plano comporta mais 4; você enviou 30"). Aceitar até encher cria a
pergunta "quais entraram", que a tela não sabe responder.

### D4 — Contadores viram registro, e a config só nomeia limite que tem contador

`countOf()` deixa de ser um `match` com `default => 0`. Vira um registro onde o
produto pluga o contador do recurso dele, com `members` como implementação de
referência.

O que sustenta isso é um teste: **todo limite nomeado por algum plano precisa ter
contador registrado**. Sem contador, falha. Com isso, `projects` e `storage_mb`
saem da config entregue — o boilerplate não tem projeto nem armazenamento, e
anunciar limite deles é vender fumaça.

### D5 — `api` vira verificação, com ordem de middleware fixada

Middleware `plan-feature:api`, declarado em `routes/api.php` ao lado do
`auth:sanctum` para aparecer no `route:list`. Aqui middleware funciona: é HTTP
puro, sem Livewire no meio.

A ordem é restrição, não detalhe: `auth:sanctum` → `SetTenantFromToken` →
`plan-feature`. A feature é escopada ao time, e o time vem do token. Fora dessa
ordem, a verificação pergunta sobre um tenant que ainda não existe.

### D6 — Quem está acima do teto

- **Ninguém é removido automaticamente.** Decidir quem sai do time é do cliente.
- **Adição é bloqueada** — o comportamento que `withinLimit` já descreve.
- **O excedente é mostrado** ("5 de 3 — acima do plano"), com os dois caminhos:
  subir de plano ou liberar assentos.
- **Não se confunde com suspensão por inadimplência.** Estados diferentes, causas
  diferentes; a tela diz qual é.
- **Rebaixar de plano estando acima do teto é permitido**, com aviso. Bloquear
  prende quem quer pagar menos e não consegue — recusa dinheiro e gera chamado.

## Desenho

### Verificação de limite

`InviteTeamMember::handle()` passa a verificar antes de criar, lançando
`PlanLimitException` — que já existe, já formata a mensagem com plano e número, e
hoje nunca é lançada.

`ImportInvitations` verifica o lote inteiro contra `withinLimit('members', count($emails))`
antes de despachar qualquer chunk.

A exceção precisa de tratamento nas duas superfícies: a tela mostra o erro no
modal; a API responde no formato Problem Details que `ApiProblem` já define, com
`type` próprio para limite de plano.

### Registro de contadores

```php
// Assinatura pretendida, não código final.
interface CountsPlanResource
{
    public function count(Team $team): int;
}
```

O registro mapeia nome do recurso para contador e é resolvido pelo container.
`TeamBilling::countOf()` consulta o registro; recurso sem contador é erro, não
zero. `members` é a referência e conta membership mais convite pendente.

O `CLAUDE.md` ganha a instrução na ordem certa: **primeiro o contador, depois o
limite na config** — porque o teste de consistência falha na ordem inversa, e é
essa a intenção.

### Recursos de plano

Middleware novo `EnsurePlanFeature`, alias `plan-feature`, resolve o time pelo
`TenantManager` e pergunta ao Pennant. A resposta negativa nomeia o plano que
inclui o recurso.

A tela `settings/api-tokens`, para quem não tem o recurso, **diz o motivo em vez
de esconder o formulário**. Sumir sem explicação é o que gera chamado.

O Pennant ganha seu primeiro consumidor real, e com ele a história que o docblock
de `PlanFeature` já prometia: suporte prometeu recurso fora do plano →
`Feature::for($team)->activate('api')`, sem ninguém editar a config de planos
para atender uma conta.

### Catálogo de planos

`config/billing.php` perde `sso` e `audit-trail` das listas de features, e
`projects` e `storage_mb` das listas de limites. O que sobra é o que o produto
sabe verificar: `api` como feature e `members` como limite.

## Arquivos afetados

| Arquivo | O quê |
|---|---|
| `app/Actions/Teams/InviteTeamMember.php` | verifica o limite antes de criar |
| `app/Actions/Teams/ImportInvitations.php` | verifica o lote inteiro |
| `app/Http/Middleware/EnsureWithinPlanLimits.php` | removido |
| `bootstrap/app.php` | alias `limits` removido, alias `plan-feature` acrescentado |
| `app/Http/Middleware/EnsurePlanFeature.php` | novo |
| `app/Support/Billing/TeamBilling.php` | `countOf()` passa a consultar o registro |
| `app/Support/Billing/ResourceCounters.php` (+ contador de membros) | novo |
| `app/Support/Api/ApiProblem.php` | tipo de problema para limite de plano |
| `config/billing.php` | catálogo enxuto |
| `routes/api.php` | `plan-feature:api` no grupo |
| `resources/views/pages/settings/⚡api-tokens.blade.php` | explica a ausência |
| `resources/views/pages/settings/⚡billing.blade.php` | mostra excedente |
| `CLAUDE.md` | como acrescentar contador e limite, nessa ordem |

## Testes

Cada teste novo precisa ser **visto falhar antes de valer**, quebrando o código
que ele cobre.

1. Convite pela tela e pela API é recusado no teto, com a mensagem nomeando plano
   e número.
2. Convite pendente conta para o teto — um time em 2 de 3 com um convite pendente
   não consegue mandar outro.
3. Lote que estoura é recusado inteiro; nenhum convite é criado.
4. Todo limite nomeado por qualquer plano tem contador registrado (varre a config).
5. Recurso sem contador é erro, não zero.
6. API responde 403 para plano sem `api`, no formato Problem Details.
7. A ordem do middleware da API é a esperada — a verificação de feature roda
   depois de o tenant existir.
8. Override do Pennant por time ganha do plano.
9. Time acima do teto: adição bloqueada, ninguém removido, excedente visível.
10. Rebaixar estando acima do teto é permitido.

O mutation testing cobre `TeamBilling` e os middlewares; a catraca está em
`--min=97` e precisa continuar verde.

## Riscos

**Ligar a verificação quebra quem já passou do teto.** Não há produção, mas o
`DemoSeeder` e a suíte criam times sem olhar plano. A suíte vai acusar; o seeder
precisa nascer dentro dos limites ou dizer explicitamente que está acima, e o
caso "acima do teto" vale um time de demonstração próprio.

**Recurso sumir do catálogo parece perda de funcionalidade.** `sso` e
`audit-trail` saindo é o catálogo parando de mentir, não o produto encolhendo. O
commit precisa dizer isso, senão daqui a seis meses alguém "restaura" as linhas.

## O que vem depois

1. **Fechar as lacunas do fluxo atual** — troca de plano com proporção, listagem
   de faturas, e o rebaixamento acima do teto ganhando fluxo próprio.
2. **A camada de adquirência com a Stripe como único driver** — tabela própria
   como fonte de verdade, Cashier virando detalhe interno do driver, com regra de
   arquitetura impedindo que ele vaze.
3. **Pix Automático e boleto na Stripe** — primeiro usuário real do modelo de
   capacidade, porque Pix Automático só faz ciclo mensal.
4. **Driver Pagar.me** — capacidades assimétricas e roteamento de qual adquirência
   atende qual time.

Cada uma ganha especificação e plano próprios.
