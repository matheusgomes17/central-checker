# Decisões de arquitetura

Um arquivo por decisão estrutural — daquelas que são caras de desfazer depois
que o produto está em pé. Cada um diz o que foi decidido, por quê, e o que foi
descartado junto, porque a alternativa recusada é a parte que se perde primeiro.

O que não está aqui: escolha de biblioteca que se troca em uma tarde, e
convenção de código, que mora no [CLAUDE.md](../../CLAUDE.md).

| # | Decisão |
|---|---|
| [0001](0001-tenancy-em-base-unica.md) | Tenancy em base única, com escopo global fail-closed |
| [0002](0002-livewire-em-vez-de-inertia.md) | Livewire e Flux no lugar de Inertia |
| [0003](0003-filament-so-no-painel-do-operador.md) | Filament só no painel do operador |
| [0004](0004-modulos-desde-o-primeiro-dia.md) | Módulos desde o primeiro dia |
| [0005](0005-assinatura-pertence-ao-time.md) | A assinatura pertence ao time |
| [0006](0006-papeis-por-time-e-super-admin-fora.md) | Papéis por time no pacote, super-admin fora dele |

Ao acrescentar um: copie o formato do 0001, numere em sequência e não edite os
antigos. Decisão revista ganha registro novo dizendo qual ela substitui — o
histórico é o que explica por que o código está como está.
