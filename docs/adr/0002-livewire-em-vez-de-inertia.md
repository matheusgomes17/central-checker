# 0002 — Livewire e Flux no lugar de Inertia

**Status:** aceita · **Fase:** 0

## Contexto

A interface precisava de reatividade real — modais, seletor de time, tabelas
que filtram — sem transformar o projeto em dois aplicativos que conversam por
JSON. As duas opções sérias eram Livewire com Flux e Inertia com React ou Vue.

## Decisão

Livewire 4 com Flux 2, e Alpine para o que é puramente de tela.

O estado mora no servidor. Autorização e validação acontecem nas actions, do
mesmo jeito que aconteceriam numa requisição HTTP comum, e não existe uma
segunda cópia da regra escrita em TypeScript para a tela decidir sozinha. Num
produto multi-tenant isso pesa: a pergunta "esta pessoa pode ver isto" tem uma
resposta só, e ela é do lado que tem o banco.

## Consequências

- Uma linguagem, um conjunto de testes. O que a tela faz é exercitável em teste
  de feature, e o que só existe no navegador é coberto por Pest Browser.
- Menos superfície de build. Vite compila CSS e um punhado de JS, não uma SPA.
- Componentes do Flux Pro não estão disponíveis: `flux:select` com
  `variant="listbox"` ou busca é pago. A regra é assumir o gratuito e conferir
  antes de usar variante.
- Interações muito ricas — arrastar e soltar complexo, edição colaborativa —
  ficam mais trabalhosas do que seriam numa SPA. Se o produto virar isso, esta
  decisão precisa ser revista para a tela em questão, não para o projeto todo.

## O que foi descartado

- **Inertia com React** — melhor para interface muito rica, ao custo de duas
  linguagens, dois conjuntos de testes e a regra de autorização tentando morar
  nos dois lados.
- **Blade puro** — sem reatividade, empurra JavaScript à mão para dentro das
  views, que é o que ninguém revisa.
