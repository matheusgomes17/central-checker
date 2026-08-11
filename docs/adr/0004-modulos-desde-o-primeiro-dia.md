# 0004 — Módulos desde o primeiro dia

**Status:** aceita · **Fase:** 0

## Contexto

`app/` cresce bem até certo ponto e depois vira uma pasta onde tudo é vizinho de
tudo. A saída usual é extrair módulos quando a dor aparece — só que aí as
dependências já estão emaranhadas, e a extração custa dias.

## Decisão

A fiação de módulos existe desde a fase 0: `modules/Core` registra providers,
rotas, migrations, views e traduções de cada módulo habilitado em
`config/modules.php`, e `php artisan make:module` cria um novo com a estrutura
pronta.

O boilerplate é entregue **sem nenhum módulo de domínio**. O que existe é o
encanamento, testado contra um módulo de mentira em `tests/Fixtures`.

## Consequências

- Extrair um domínio deixa de ser refatoração e passa a ser uma decisão: o lugar
  já existe, e mover para lá é barato.
- `app/` continua sendo o lugar certo para o que é da aplicação inteira — models
  de time e usuário, middleware, policies de plataforma.
- Existe um segundo lugar para procurar código, o que é custo real de leitura.
  A regra que segura isso: só promova a módulo o que tem fronteira própria de
  verdade, não o que só está grande.
- O sistema de módulos é código nosso e precisa de manutenção. Ele é pequeno de
  propósito por isso.

## O que foi descartado

- **Pacote de terceiro para módulos** — traz convenções, comandos e um ciclo de
  atualização para resolver algo que aqui cabe em um provider.
- **Esperar a dor** — que é quando o custo de extrair é máximo.
- **Domain-driven do início** — camadas e abstrações sem três usos reais para
  justificar cada uma. A regra do projeto é a oposta.
