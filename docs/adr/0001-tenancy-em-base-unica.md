# 0001 — Tenancy em base única, com escopo global fail-closed

**Status:** aceita · **Fase:** 2

## Contexto

O tenant deste boilerplate é o **time**, não o usuário: uma pessoa pertence a
vários times e tem papel diferente em cada um. Isso já elimina a ideia de
pendurar `team_id` no usuário e resolver a partir dele.

Restava escolher como separar os dados. Base por tenant e schema por tenant
isolam melhor no papel, e cobram caro em tudo o mais: migração passa a ser um
laço sobre N bases, o pool de conexões multiplica, relatório entre times exige
costura manual, e o custo de criar um time deixa de ser um `INSERT`. Para um
boilerplate que quer que criar time seja barato, isso é caro no lugar errado.

## Decisão

Base única, coluna `team_id`, e um **escopo global que falha fechado**.

Fail-closed é a parte que importa. O escopo não é "filtre por tenant se houver
um"; é "sem tenant no contexto, não leia" — e escrever um model escopado sem
tenant definido lança exceção em vez de gravar `null`. Uma linha sem dono é
invisível para todos os times depois: seria perda de dado silenciosa, e perda
silenciosa é pior que erro barulhento.

O escopo tem duas metades e as duas são necessárias: a leitura filtra, e o
`creating` carimba. Só a primeira deixaria escrever fora do time.

Atravessar o escopo é possível e é explícito: `acrossTenants()` na consulta e
`withoutTenancy()` num bloco. Ambos aparecem no ponto de uso, que é onde a
revisão consegue ver.

## Consequências

- Todo model de domínio nasce escopado. Quem não deve ser precisa de entrada
  justificada em `TenantManager::UNSCOPED_MODELS` — e a lista é lida tanto pela
  regra de arquitetura quanto pelo `php artisan tenant:audit`.
- O risco de vazamento entre times não desaparece: ele muda de "acontece por
  padrão" para "acontece se alguém adicionar model e não perceber". É o que a
  regra de arquitetura e a suíte de isolamento cobrem.
- Índice por `team_id` em toda tabela escopada, porque toda leitura passa por
  ele. O `make:tenant-model` já gera com índice.
- Isolamento é lógico, não físico. Um produto com exigência regulatória de
  separação física precisa revisar esta decisão, não contorná-la.

## O que foi descartado

- **Base por tenant** — isolamento físico ao custo de migração, conexões e
  provisionamento. Caro para o tamanho de time que este boilerplate atende.
- **Schema por tenant no Postgres** — meio-termo que herda o pior dos dois:
  ainda é N migrações, e o `search_path` vira estado global fácil de esquecer.
- **RLS do Postgres** — atraente, e continua na mesa como reforço futuro. Não
  entrou como mecanismo principal porque a regra passaria a viver fora do
  código da aplicação, onde a suíte não a alcança.
