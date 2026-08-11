# 0005 — A assinatura pertence ao time

**Status:** aceita · **Fase:** 5

## Contexto

O Cashier assume o model `User` como cobrável. Aqui isso estaria errado: quem
contrata é o time, quem paga a conta pode ser uma pessoa que nem administra o
time, e quem criou a conta pode sair da empresa. Se a assinatura pendurar no
usuário, a saída dele leva o plano junto.

## Decisão

`Team` é o cobrável. As colunas do Cashier ficam em `teams`, `subscriptions`
tem `team_id`, e o Cashier é apontado para o model do time antes de qualquer
coisa resolver um cobrável.

O plano é gravado pelo **identificador interno**, não pelo `price_id` da
Stripe. O price muda entre ambientes e quando o preço é reajustado; o
identificador não. Gravar o price seria perder de vista quem está em qual plano
no primeiro reajuste.

Existe um papel `billing` por time, que dá acesso à cobrança sem dar acesso ao
time. Quem paga a conta não precisa poder convidar nem remover ninguém.

## Consequências

- Trocar de dono não mexe na assinatura.
- Limite de plano é pergunta sobre o time, respondida por middleware, e vale
  igual na interface e na API.
- Time inadimplente é suspenso, e a suspensão precisa de exceções explícitas —
  a pessoa tem que conseguir chegar às telas de cobrança para voltar a pagar.
- Uma pessoa em vários times pode estar em planos diferentes ao mesmo tempo. É o
  comportamento certo e é uma fonte de confusão em teste: o plano depende do
  time atual, não do usuário.

## O que foi descartado

- **Assinatura no usuário** — o padrão do Cashier, e errado para produto vendido
  a organizações.
- **Gravar o `price_id`** — some no primeiro reajuste de preço.
- **Cobrança própria sem Cashier** — reimplementar webhook, período de teste,
  falha de pagamento e SCA, que é onde os bugs caros moram.
