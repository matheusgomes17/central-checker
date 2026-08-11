# 0006 — Papéis por time no pacote, super-admin fora dele

**Status:** aceita · **Fase:** 3

## Contexto

O starter kit já trazia papéis por time em enums, e o `spatie/laravel-permission`
traz o mesmo com tabelas, cache e modo multi-time. Manter os dois significava
duas fontes de verdade para a mesma pergunta — e a coluna `team_members.role`
existia junto, o que fazia três.

Havia ainda a pergunta de plataforma: o que dá acesso ao `/admin` e permite
impersonar não é papel dentro de um time.

## Decisão

O pacote é a única fonte das **atribuições**; os enums continuam sendo a fonte
do que **existe**. `RolesAndPermissionsSeeder` projeta os enums nas tabelas, com
`sync` e não `append` — papel que perde permissão no enum precisa perdê-la no
banco. A coluna `team_members.role` foi removida.

Papéis de time e papéis de plataforma ficam separados. `SystemRole::SuperAdmin`
não vive no pacote: em modo multi-time o pacote não guarda atribuição sem time,
e um super-admin com time seria um privilégio de plataforma que parece limitado
a um time e não é. Ele mora no usuário, e só se concede pela linha de comando —
não há tela para isso, de propósito.

## Consequências

- Uma pergunta, um lugar: `teamRole($team)` responde por time, `isSuperAdmin()`
  responde por plataforma.
- Um `Gate::before` libera tudo para super-admin, o que faz qualquer `Gate` que
  tente restringir super-admin não restringir nada. Isso já enganou um teste
  nosso: o que protege o Telescope são as camadas acima dele, não o gate.
- O cache de permissões do pacote é real. Semear em processo que já tinha cache
  quente falha com "permission does not exist" até o cache ser esvaziado.
- Matriz de papel × ação × recurso tem teste próprio, porque é a parte que
  ninguém revisa lendo.

## O que foi descartado

- **Só os enums do starter kit** — sem permissões nomeadas, cada verificação
  vira `if` sobre papel espalhado pelo código.
- **Só o pacote** — perde-se a hierarquia expressa em enum, que é o que permite
  perguntar "pelo menos admin" sem listar papéis.
- **Super-admin como papel de time** — leria como limite de segurança sem ser
  um.
