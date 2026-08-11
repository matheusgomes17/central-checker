# Troca de plano e faturas

**Data:** 2026-07-28 · **Estado:** aprovado, aguardando plano de implementação
**Antecede:** [ligar o plano ao produto](2026-07-28-planos-ligados-ao-produto-design.md), fase 0 desta sequência

## O problema

Três coisas quebradas ou mentindo na tela de cobrança de hoje.

**Quem já assina e clica em outro plano não faz upgrade.** `BillingController::checkout`
chama `newSubscription()` sem olhar se já existe assinatura. A troca real só
acontece pelo portal hospedado da Stripe — dentro do produto, o botão leva a um
fluxo de assinatura nova.

**A tela promete faturas e não mostra nenhuma.** O subtítulo diz "Plan, usage and
invoices for this team"; não há listagem em lugar nenhum.

**Rebaixar de plano acontece sem aviso.** A fase 0 decidiu que rebaixar estando
acima do teto é permitido — bloquear prenderia quem quer pagar menos. Mas
permitir em silêncio é diferente de permitir: hoje nada diz à pessoa que o time
vai ficar sem poder adicionar ninguém.

## Decisões

### D1 — Proporção imediata nos dois sentidos

Subir e descer valem na hora. A Stripe calcula a diferença ou o crédito e aplica
na próxima fatura. É o padrão do Cashier: basta **não** chamar `noProrate()`.

Um caminho de código só, para os dois sentidos. Quem desce vê crédito na próxima
cobrança, não reembolso — e a tela diz isso antes de confirmar, porque "desci de
plano e não voltou dinheiro" é chamado de suporte garantido se ninguém avisar.

Descartado: subir cobrando na hora e descer só no fim do período. É o mais comum
em SaaS e combina com a carência que o cancelamento já tem, mas o Cashier não
agenda troca futura — exigiria guardar a intenção e aplicar depois, com um
segundo estado para manter em pé.

### D2 — Faturas lidas ao vivo, com cache curto

`$team->invoices()` consulta a Stripe a cada carregamento. A alternativa —
guardar localmente por webhook `invoice.paid` — é mais rápida e cria uma tabela
a mais para divergir da Stripe, que é a fonte de verdade de fatura de qualquer
jeito. A tela de cobrança não é quente.

### D3 — O download passa por `downloadInvoice()`, nunca por `findInvoice()`

O construtor de `Laravel\Cashier\Invoice` lança `InvalidInvoice::invalidOwner`
quando a fatura não é do cobrável, e `findInvoiceOrFail()` converte isso em 403.
O `findInvoice()` cru **não** captura essa exceção.

Usar o método errado ali é um time baixando fatura de outro — vazamento entre
tenants pelo caminho mais bobo possível. Isso ganha teste próprio, e não um
comentário.

### D4 — Rebaixamento avisa antes, e exige confirmação

Antes de aplicar uma troca que deixa o time acima do teto do plano novo, a tela
diz o que vai acontecer: quantos assentos o plano comporta, quantos o time usa,
que ninguém será removido, e que não será possível adicionar até liberar
assentos. Confirmação explícita.

Isto não muda a regra da fase 0 — rebaixar continua permitido. Muda que deixa de
ser um clique sem consequência visível.

## Escopo

**Entra:** troca de plano de dentro do produto com proporção; listagem e download
de faturas; aviso e confirmação no rebaixamento que estoura o teto.

**Não entra:** cupom e desconto; imposto e nota fiscal; plano anual e troca de
intervalo de cobrança; agendamento de troca para o fim do período. Nenhum é
pré-requisito dos outros, e cada um traz decisão de produto própria.

## Desenho

### Troca de plano

`BillingController::checkout` passa a bifurcar por estado, em vez de sempre criar
assinatura nova:

- **Time sem assinatura** → checkout hospedado, como hoje.
- **Time com assinatura** → `App\Actions\Billing\SwapPlan`, que resolve na hora e
  devolve para a tela sem passar pela Stripe.

A action segue a convenção do projeto: um verbo, um `handle`, nenhum segundo
método público. `TeamBilling` continua sendo só leitura — a escrita mora na
action, como `InviteTeamMember` faz para times.

Autorização pela permissão `ManageBilling`, a mesma que o controller já exige
para checkout e portal. O time vem do `TenantManager`, não do parâmetro.

Recusas a tratar:

| Caso | Resposta |
|---|---|
| Plano igual ao atual | Não faz nada, avisa na tela |
| Plano sem price id | Já tratado hoje pelo `Plan::purchasable()` |
| `IncompletePayment` (SCA) | Mesmo tratamento que o checkout já dá: redireciona para a confirmação de pagamento do Cashier |

### Faturas

A tela lista data, valor, situação e download. O download é rota própria
(`billing.invoice`), que chama `downloadInvoice()` do time atual — o que faz a
verificação de dono acontecer dentro do Cashier, onde ela existe.

Time sem `stripe_id` não tem fatura nenhuma, e `invoices()` já devolve coleção
vazia nesse caso — a tela mostra o estado vazio, não um erro.

### Rebaixamento

A confirmação só aparece quando o plano de destino tem teto menor que o consumo
atual. Para os demais casos a troca é direta — pedir confirmação para toda troca
treina a pessoa a clicar sem ler, que é o oposto do que a confirmação existe
para fazer.

O texto usa os números reais do `TeamBilling::usage()`, não uma frase genérica.

## Testes

Cada teste novo precisa ser **visto falhar antes de valer**.

1. Time sem assinatura clicando num plano vai para o checkout hospedado.
2. Time com assinatura clicando em outro plano **troca**, e a assinatura passa a
   apontar para o novo preço — sem criar segunda assinatura.
3. Trocar para o plano que já é o atual não faz nada e avisa.
4. Quem não tem `ManageBilling` não troca.
5. A lista de faturas aparece; time sem `stripe_id` vê o estado vazio.
6. **Um time não baixa a fatura de outro** — 403, não 404 e não o arquivo.
7. Rebaixar com o time acima do teto do plano novo pede confirmação, e a
   confirmação diz os números certos.
8. Rebaixar dentro do teto não pede confirmação.

Os testes que tocam a Stripe de verdade continuam no `StripeIntegrationTest`,
opt-in por `STRIPE_INTEGRATION=true`. A troca de plano ganha um caso lá: `swap()`
contra a conta de teste, confirmando que a assinatura muda de preço.

## Riscos

**A proporção é dinheiro, e o teste offline não a verifica.** Os testes de
feature montam linhas de `subscriptions` e não falam com a Stripe, então o
cálculo do crédito não é exercitado por eles. Quem mexer nisso precisa rodar o
`StripeIntegrationTest` com chave, e o registro da fase precisa dizer se rodou.

**A leitura ao vivo acopla a tela à disponibilidade da Stripe.** Com a Stripe
fora, a tela de cobrança fica sem a lista. O aceitável é a lista falhar sozinha,
com o resto da tela de pé — não a página inteira cair por causa de uma seção.
