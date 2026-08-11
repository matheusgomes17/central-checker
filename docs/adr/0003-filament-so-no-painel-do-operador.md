# 0003 — Filament só no painel do operador

**Status:** aceita · **Fases:** 0 e 4

## Contexto

O Filament resolve em uma tarde o que custa uma semana à mão: tabela com busca,
filtro, ordenação, exportação, ações em massa. A tentação é usá-lo também nas
telas do produto — a de times, a de membros, a de cobrança.

São públicos diferentes. O painel do operador é usado por quem mantém a
plataforma, tolera densidade e vive de listar e agir em massa. O produto é usado
pelo cliente, precisa da identidade visual dele e de fluxos guiados.

Havia ainda um risco técnico: Filament 5 e Livewire 4 no mesmo aplicativo. Foi
por isso que ele entrou já na fase 0 — o spike de convivência precisava
acontecer antes de qualquer coisa ser construída em cima.

## Decisão

Filament vive em `/admin` e em nenhum outro lugar. As telas do produto são
Livewire com Flux.

O acesso ao painel é fechado por regra — `canAccessPanel()` exige super-admin —
e não pelo fallback de ambiente do próprio Filament, que só protege enquanto o
`APP_ENV` estiver certo.

## Consequências

- Duas linguagens visuais no mesmo repositório, de propósito. Quem mexe no
  produto não precisa aprender Filament; quem mexe no painel não precisa
  aprender Flux.
- Recursos do painel podem ser gerados sem cerimônia: nenhum cliente os vê.
- A convivência das duas pilhas tem teste próprio. Se uma atualização quebrar,
  a suíte diz — não a página em branco.
- Ceder e usar Filament numa tela de cliente traz junto o tema, o layout e o
  fluxo dele. É a forma mais provável de esta decisão se desfazer sem ninguém
  decidir nada.

## O que foi descartado

- **Filament no produto inteiro** — rápido no começo, e depois o produto parece
  o painel de administração de outra pessoa.
- **Painel do operador à mão** — semanas construindo tabela com filtro que já
  existe pronta, para um público que não liga para o visual.
