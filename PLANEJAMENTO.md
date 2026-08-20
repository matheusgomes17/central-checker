# Planejamento — Boilerplate Laravel (SaaS multi-tenant)

> Documento de planejamento. Nenhum código foi escrito ainda.
> Versões abaixo foram consultadas no Packagist em **25/07/2026** — reconfirme antes de iniciar.

---

## 1. Decisões fechadas

| Eixo | Decisão |
|---|---|
| Frontend | Livewire 4 + Flux UI (Volt opcional) |
| Admin | Filament 5 |
| Escopo do produto | SaaS multi-tenant (times/organizações, convites, papéis, billing) |
| Ambiente local | Laravel Sail (Docker) como padrão do time + Herd para iteração rápida |
| Deploy | **Pendente** — ver §10 |

### Stack e versões verificadas

| Pacote | Versão estável | Papel |
|---|---|---|
| `laravel/framework` | **13.22.0** (requer PHP `^8.3`) | Core |
| `livewire/livewire` | **4.3.3** | UI reativa em PHP |
| `livewire/flux` | **2.15.0** | Design system (free + Pro pago) |
| `livewire/volt` | 1.11.1 | Single-file components *(opcional — Livewire 4 já tem SFC nativo)* |
| `filament/filament` | **5.7.3** | Painel admin + tenancy nativa |
| `laravel/cashier` | **16.6.0** | Billing Stripe |
| `laravel/sanctum` | 4.3.3 | Tokens de API / SPA auth |
| `laravel/fortify` | 1.37.3 | Backend de auth (2FA, recovery codes) |
| `laravel/horizon` | 5.48.1 | Filas Redis + dashboard |
| `laravel/pulse` | 1.7.4 | Métricas de aplicação |
| `laravel/telescope` | 5.21.0 | Debug local |
| `laravel/reverb` | 1.11.0 | WebSockets |
| `laravel/scout` | 11.4.0 | Busca (Meilisearch) |
| `laravel/pennant` | 1.24.0 | Feature flags |
| `laravel/socialite` | 5.29.0 | OAuth social |
| `laravel/octane` | 2.18.0 | Runtime FrankenPHP *(fase tardia)* |
| `laravel/boost` | 2.4.13 | MCP server — docs Laravel para agentes de IA |
| `laravel/mcp` | 0.9.1 | Expor ferramentas MCP do próprio app |
| `spatie/laravel-permission` | 8.3.0 | RBAC com escopo por team |
| `spatie/laravel-data` | 4.23.0 | DTOs tipados |
| `spatie/laravel-activitylog` | 5.0.0 | Auditoria |
| `spatie/laravel-query-builder` | 7.3.0 | Filtros/sorts de API |
| `dedoc/scramble` | 0.13.35 | OpenAPI automático |
| `pestphp/pest` | **4.7.5** | Testes (unit, feature, browser, mutation) |
| `larastan/larastan` | 3.10.0 | Análise estática |
| `laravel/pint` | 1.29.3 | Formatação |
| `rector/rector` | 2.5.7 | Refactor automatizado |
| `peckphp/peck` | 0.3.0 | Spell check de código |

**Notas de compatibilidade**
- Laravel 13 exige PHP `^8.3`. Local: **PHP 8.4.1** — OK.
- Flux 2.15 e Volt 1.11.1 já declaram suporte a `livewire/livewire ^4.0`.
- Filament 5.7.3 exige PHP `^8.2` — compatível.
- `livewire/volt` continua mantido, mas Livewire 4 traz single-file components nativos. **Recomendação: não instalar Volt**, usar SFC nativo. Menos uma dependência.

---

## 2. Pré-requisitos de ambiente

| Item | Estado | Ação |
|---|---|---|
| PHP 8.4.1 | OK | — |
| Composer 2.8.12 | OK | — |
| Docker 29.6.2 | OK | — |
| Laravel installer | OK (herd-lite) | — |
| Node.js / npm | OK (22 LTS) | Resolvido na Fase 0 |
| Conta Stripe (test mode) | OK | Chaves configuradas; `StripeIntegrationTest` passa contra a conta de teste |
| Licença Flux Pro | ? | Opcional — Flux free cobre o essencial |

**Primeiro comando do projeto, antes de qualquer coisa:**
```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/master/install.sh | bash
nvm install 22 && nvm use 22 && node -v
```

---

## 3. Arquitetura

### 3.1 Módulos desde o início *(decisão do usuário — implementado na Fase 0)*

O plano original propunha promover para módulos só quando um domínio crescesse. A decisão foi adotar módulos desde o dia 1. O risco real dessa escolha — o boilerplate virar um mini-framework com cerimônia sem ganho — é mitigado por **fronteiras testadas**, não por disciplina informal: a suíte `Arch` reprova qualquer dependência entre módulos irmãos.

```
modules/
├── Core/                 # a infraestrutura do sistema de módulos
│   ├── Console/Commands/MakeModuleCommand.php
│   ├── Providers/{CoreServiceProvider,ModuleServiceProvider}.php
│   ├── Support/Modules.php
│   └── tests/
└── <Nome>/
    ├── Actions/  Enums/  Models/  Policies/  Providers/   # classes PHP
    ├── database/{factories,migrations}
    ├── resources/views                                     # namespace "<nome>::"
    ├── routes/{web,api,console}.php
    └── tests/Feature
```

**Como funciona**

- Um único mapeamento PSR-4 no `composer.json` (`Modules\ → modules/`). Criar módulo novo **não** exige tocar no autoload.
- `config/modules.php` lista os módulos habilitados; a ordem é a ordem de registro. Remover um módulo do boilerplate = tirar da lista e apagar o diretório.
- `ModuleServiceProvider` registra sozinho migrations, traduções, rotas e um namespace de views compartilhado por Blade, componentes anônimos e single-file components do Livewire — espelhando como o Livewire registra o namespace `pages`.
- `CoreServiceProvider` ensina o Laravel a resolver factories e policies dentro do módulo (`Modules\X\Models\Y` → `Modules\X\{Database\Factories,Policies}`).
- `php artisan make:module <Nome>` gera a estrutura e habilita o módulo.

**Regra de dependência (verificada no CI)**

Um módulo pode depender de `Core` e de si mesmo. **Nunca de um irmão.** Comunicação entre domínios acontece por eventos ou pela camada de aplicação (`app/`), que é o ponto de composição e pode depender de todos. `Core` não depende de ninguém.

> A regra foi validada introduzindo uma dependência `Core → Billing` de propósito e confirmando que a suíte reprova. Regra de arquitetura que nunca falha não protege nada.

**Camada de aplicação (`app/`)** permanece como ponto de composição: `App\Models\User` compõe traits vindas dos módulos, e `bootstrap/providers.php` registra o `CoreServiceProvider`.

### 3.2 Estratégia de multi-tenancy

**Escolha: single-database, row-level scoping** (`team_id` em cada tabela tenant-aware).

Justificativa:
- Migrations únicas, backup único, dev local trivial.
- Filament 5 tem tenancy de primeira classe nesse modelo.
- Cashier assina o `Team`, não o `User` — modelo natural de SaaS B2B.

Implementação:
- Trait `BelongsToTenant` que registra um **global scope** + preenche `team_id` no `creating`.
- `TenantManager` singleton resolvendo o tenant atual de: sessão (web), header/token (API), ou `Filament::getTenant()`.
- **Regra de segurança inegociável:** todo model tenant-aware herda de uma base class que aplica o scope. Um teste de arquitetura (Pest arch) falha o CI se algum model em `app/Models` sem allowlist não usar a trait.

*Alternativa rejeitada:* `stancl/tenancy` (3.10.0) com database-per-tenant. Superior para isolamento regulatório/enterprise, mas custo operacional (N migrations, N backups) não se paga num boilerplate genérico. Documentar como caminho de migração.

### 3.3 Camada de superfícies

| Superfície | Rota base | Auth | Stack |
|---|---|---|---|
| Marketing/público | `/` | — | Blade + Flux |
| App do usuário | `/app/{team}` | session + tenant | Livewire 4 + Flux |
| Admin do operador | `/admin` | session + role `super-admin` | Filament 5 |
| API v1 | `/api/v1` | Sanctum token | Controllers + Resources |
| Webhooks | `/webhooks/stripe` | assinatura | Cashier |
| Broadcast | Reverb | — | — |

---

## 4. Fases de implementação

Estimativas para **1 dev sênior full-time**. Cada fase tem critério de aceite objetivo — não avança sem verde.

---

### Fase 0 — Fundação e tooling *(1–2 dias)*

**Objetivo:** repositório que já reprova código ruim antes de existir código de negócio.

- `laravel new boilerplate-laravel` com starter kit **Livewire**
  → confirmar flags disponíveis com `laravel new --help` (mudam entre majors)
- Git init, `main` protegida, Conventional Commits
- **Pint** com preset `laravel` + regras extras (`declare_strict_types`, ordenação de imports)
- **Larastan** nível **max** desde o dia 1 (baseline vazio; subir nível depois nunca acontece)
- **Rector** com `LaravelSetList` + `LevelSetList::UP_TO_PHP_84`
- **Pest 4** + `pest-plugin-laravel` + `pest-plugin-type-coverage`
- **Peck** para spell check
- **Testes de arquitetura** (Pest arch) já na Fase 0:
  - controllers não usam `DB::` direto
  - models não têm métodos com mais de N linhas
  - nada em `app/` usa `dd`/`dump`/`ray`
  - todo arquivo tem `declare(strict_types=1)`
- **Sail** com PostgreSQL 17, Redis, Mailpit, Meilisearch, MinIO
- Script único `composer check` = pint + rector dry + larastan + pest + peck
- `.env.example` completo e comentado
- **Laravel Boost** instalado (MCP com docs versionadas do Laravel — acelera muito trabalho assistido por IA)

**Critério de aceite:** `composer check` verde em repo limpo; `sail up` sobe todos os serviços; `sail artisan migrate` OK.

#### ✅ Fase 0 — CONCLUÍDA

`composer check` sai com **exit 0**: Pint, Rector, PHPStan `max` (**0 erros, sem baseline**), Peck, **91 testes** (1 pulado) e **100% de type coverage**. Containers sobem e as migrations rodam no Postgres 18.

**Auditoria da fase (26/07/2026).** Conferido item a item contra esta seção. Estavam faltando três coisas, todas fechadas:

| Lacuna | Resolução |
|---|---|
| Dos 4 testes de arquitetura previstos, faltava o de tamanho de model | O Pest mede linhas por arquivo, não por método; a regra usa o arquivo como proxy, com limite de 150 linhas. Validada inflando um model até reprovar. |
| Spike Filament 5 + Livewire 4 (risco §7) nunca foi feito | Feito. As duas stacks convivem — ver `tests/Feature/FilamentCoexistenceTest.php`. |
| `modules/Billing` estava commitado vazio e habilitado | Removido. O boilerplate não entrega módulo de domínio nenhum; os testes do sistema de módulos passaram a usar fixture própria e a exercitar o `make:module` direto. |

Continua **impossível** proteger a branch `main`: o repositório ainda não tem remote.

**Resultado do spike do Filament.** O painel responde em `/admin` sem disputar o endpoint de update do Livewire nem o namespace de componentes do app. Dois efeitos colaterais do instalador que precisam de atenção em qualquer reinstalação:

- ele reescreve `bootstrap/providers.php` em ordem alfabética e sem `declare(strict_types=1)`, jogando o `CoreServiceProvider` para o fim — e a ordem importa, porque é ele que registra os providers dos módulos;
- publica ~3,5 MB de assets em `public/{css,js,fonts}/filament`. Foram para o `.gitignore`; `filament:assets` entrou no `composer setup` e **precisa rodar em todo deploy**.

Até a Fase 4 dar ao `User` uma implementação de `canAccessPanel()`, o que fecha o painel é o fallback do próprio Filament: sem o contrato `FilamentUser`, o acesso só é liberado em ambiente `local`. Isso está fixado em teste.

**Desvios em relação ao plano original, e por quê:**

| Planejado | Entregue | Motivo |
|---|---|---|
| Muito a construir na Fase 1–3 | Starter kit já traz Fortify, **2FA, passkeys**, times com `TeamRole`/`TeamPermission`, `TeamPolicy` e `Membership` | O `laravel new` do Laravel 13 tem `--teams`; o escopo das Fases 1–3 encolheu bastante |
| Volt para SFC | **Fora** | Livewire 4 tem single-file components nativos |
| Matriz de CI 8.3 + 8.4 | **Só 8.4** | O Rector aplica o set de PHP 8.4; a base usa sintaxe que não roda em 8.3 |
| Sail em PHP 8.5 (padrão do instalador) | **Fixado em 8.4** | Alinha Docker, Herd e CI num único runtime |
| `spatie/laravel-permission` na Fase 3 | **A reavaliar** | O starter kit já traz papéis e permissões por time via enums; o pacote pode ser redundante |
| Chisel para "podar o boilerplate" (Fase 10) | Já vem instalado | `laravel/chisel` é dependência do starter kit |
| Filament instalado só na Fase 4 | **Instalado na Fase 0** | O §7 pede o spike de convivência antes de construir por cima; instalar era pré-requisito do spike |

**Decisão de ambiente:** o `.env` aponta para `127.0.0.1` e o `compose.yaml` sobrescreve os hostnames dentro do container. Assim o mesmo `.env` serve Herd e Sail, e o pipeline roda inteiro no host — onde estão o `aspell` (exigido pelo Peck) e o Node.

> ⚠️ **Não rode `artisan` via `docker compose exec`** — cria arquivos com dono `root` no projeto. Use `./vendor/bin/sail artisan`, que faz o mapeamento de usuário.

> ⚠️ **Não alterne a suíte entre host e container.** O `livewire/blaze` guarda caminhos absolutos das views compiladas em `storage/framework/views`. Rodar os testes no host depois de rodar no Sail (ou vice-versa) quebra com `filemtime(): stat failed`. O pipeline é host-only; se precisar trocar, rode `php artisan view:clear` antes.

---

### Fase 1 — Autenticação e conta *(2 dias)*

- Fortify: login, registro, reset de senha, verificação de e-mail, confirmação de senha
- **2FA com TOTP + recovery codes** (Fortify entrega; ativar de fato)
- Socialite: Google + GitHub, com merge de conta por e-mail verificado
- Perfil: nome, e-mail (com re-verificação), avatar (storage S3/MinIO), timezone, locale
- Sessões ativas: listar e revogar dispositivos
- Exclusão de conta com confirmação de senha + soft delete + job de purga em 30 dias (LGPD)
- Rate limiting nomeado em login/reset
- Telas em Livewire 4 + Flux

**Critério de aceite:** suíte Pest cobrindo os 12 fluxos de auth; 2FA testado ponta a ponta; teste de que usuário A não lê sessão de B.

#### ✅ Fase 1 — CONCLUÍDA

`composer check` sai com **exit 0**: **144 testes** (1 pulado), 369 asserções, PHPStan `max` sem erros e 100% de type coverage.

O starter kit já entregava login, registro, reset, verificação de e-mail, confirmação de senha, 2FA com recovery codes e passkeys. O que foi construído:

| Item | Como ficou |
|---|---|
| Rate limiting nomeado | O endpoint de reset não estava aberto — o password broker já limita por e-mail. A brecha era **por origem**: um IP podia percorrer uma lista de endereços. Limiter por IP em `password.email`, `password.update` e, por decisão sua, `register.store`. |
| Sessões ativas | Lista e revoga dispositivos lendo a tabela de sessões. Fecha o critério de aceite: o filtro por `user_id` é a única coisa separando as contas, e removê-lo reprova dois testes. |
| Exclusão de conta | Soft delete + `users:purge` agendado. Sem o comando, soft delete vira retenção eterna. |
| Perfil | Avatar no MinIO (o banco guarda caminho, não URL), idioma e fuso. O middleware aplica o idioma de fato; o fuso formata o horário na lista de sessões. |
| Socialite | Google e GitHub. Vínculo pelo par (provedor, id), e merge só com e-mail verificado pelo provedor. |

**Três achados que valem para as próximas fases:**

1. **Flux Pro apareceu.** `flux:select` com `variant="listbox"` e busca é pago. Foi trocado pelo select do free. É o primeiro caso concreto do risco §7 — a regra prática é assumir o free e verificar antes de usar variante.
2. **A suíte estourava o `memory_limit` de 128 MB de forma intermitente.** O limite foi para o `phpunit.xml`, não para o script do composer: o `artisan test` inicia um processo filho que **não herda `-d`**. Quem for ajustar limites de PHP em teste precisa saber disso.
3. **Contas criadas por OAuth não têm senha utilizável.** Recebem um valor aleatório para manter a coluna não nula; para ganhar senha, o caminho é o fluxo de recuperação.

**Nota de segurança para a Fase 2:** o `AuthenticateWithSocialProvider` recusa e-mail não verificado tanto para vincular quanto para criar. Afrouxar isso reabre sequestro de conta — há teste cobrindo os dois casos.

---

### Fase 2 — Multi-tenancy *(3 dias)*

- Models: `Team`, `TeamInvitation`, pivot `team_user` com `role`
- Trait `BelongsToTenant` + `TenantScope` + `TenantManager`
- Middleware `EnsureTenantIsSet` + route model binding por slug
- Criação de team no registro (personal team) + criação manual
- Convites por e-mail com token assinado, expiração e reenvio
- Troca de team (switcher no header, persistido em sessão)
- Transferência de ownership; remoção de membro; sair do team
- Comando `artisan tenant:audit` — varre tabelas e aponta as que têm `team_id` sem índice ou sem scope

**Critério de aceite:**
- Teste que percorre **todos** os models tenant-aware e prova vazamento zero entre dois teams.
- Teste de arquitetura: model novo sem `BelongsToTenant` e fora da allowlist reprova o CI.

> Esta é a fase de maior risco do projeto. Vazamento de tenant é falha crítica de segurança. Orçar tempo de teste maior que o de implementação.

#### ✅ Fase 2 — CONCLUÍDA

`composer check` sai com **exit 0**: **177 testes** (1 pulado), 437 asserções, PHPStan `max` sem erros e 100% de type coverage.

O starter kit já trazia `Team`, `TeamInvitation`, pivot com papel, criação de time no registro, troca de time, convites com expiração, remoção de membro e sair do time. O que faltava era justamente a camada que **impede** vazamento — e ela não existia.

| Item | Como ficou |
|---|---|
| `BelongsToTenant` + `TenantScope` + `TenantManager` | Escopo global **fail-closed**: sem tenant no contexto, nenhuma linha. Criar sem tenant lança exceção em vez de gravar órfão. |
| Teste de vazamento zero | Roda contra um model de fixture, já que o boilerplate não tem model de domínio próprio. Remover o filtro do escopo reprova 6 testes. |
| Regra de arquitetura | Model novo sem a trait e fora da allowlist reprova o CI. Verificado criando um. |
| `tenant:audit` | Acusa tabela com `team_id` sem model escopado e tabela escopada sem índice útil. Sai com código ≠ 0, roda no CI. |
| Transferência de propriedade | Não existia. Invariante: exatamente um dono, antes e depois. Exige confirmação de senha. |
| Reenvio de convite | Não existia. Renova a janela, tem cooldown, não rotaciona o código. |

**A decisão de projeto que define a fase:** o que fazer quando não há tenant no contexto. Deixar a query passar sem filtro significa que qualquer caminho que perca o tenant devolve as linhas de todos os times. O escopo falha fechado, e trabalho legítimo entre times passa por `TenantManager::withoutTenancy()` — que é callback, não flag, para que o escopo volte mesmo se o callback lançar.

**`EnsureTenantIsSet` não foi criado.** O plano previa um middleware dedicado, mas ele seria redundante: o `EnsureTeamMembership` já resolve o time da rota e chama `switchTeam`, e o `TenantManager` cai no time atual do usuário autenticado quando ninguém definiu nada. Um middleware a mais só daria a impressão de proteção adicional.

**Três armadilhas encontradas, todas registradas em comentário no código:**

1. `gatherMiddleware()` do Laravel faz cache em `computedMiddleware` — ler a lista antes de mutar a rota congela o middleware.
2. `saveQuietly()` silencia eventos, **não** timestamps: regravar `updated_at` com o mesmo valor não deixa o atributo sujo e o Eloquent recarimba com "agora".
3. Derivar nome de classe a partir do caminho quebra com PSR-4: `Modules\` mapeia para `modules/`.

**Nota para a Fase 3:** a allowlist de models não escopados mora em `TenantManager::UNSCOPED_MODELS` e é lida tanto pela regra de arquitetura quanto pelo `tenant:audit`. Cada entrada nova precisa de justificativa escrita — a lista que crescer sem isso é a que deixa de proteger.

---

### Fase 3 — Autorização e RBAC *(2 dias)*

- `spatie/laravel-permission` 8.x com **teams feature ativada**
- Papéis padrão: `owner`, `admin`, `member`, `billing` + `super-admin` global
- Permissões granulares por recurso, seed idempotente
- Policies para todo model de domínio; `Gate::before` para super-admin
- UI de gestão de papéis (Livewire + Flux)
- `spatie/laravel-activitylog` — auditoria de mudanças sensíveis (papel, billing, remoção de membro)
- Impersonation de usuário pelo super-admin, **com log obrigatório e banner visível**

**Critério de aceite:** matriz de testes papel × ação × recurso; nenhuma rota autenticada sem policy ou justificativa explícita.

#### ✅ Fase 3 — CONCLUÍDA

`composer check` sai com **exit 0**: **222 testes** (1 pulado), 622 asserções, PHPStan `max` sem erros e 100% de type coverage.

**A decisão da fase.** Eu recomendei ficar com os enums e não instalar o `spatie/laravel-permission`; você decidiu seguir o plano. Entregue no escopo completo, com uma síntese: os enums seguem como vocabulário tipado dos papéis e permissões, e o pacote é a fonte das atribuições. A coluna `team_members.role` **caiu** — mantê-la seria dar duas respostas a "qual é o papel desta pessoa aqui", e o dia em que divergirem é um bug de autorização silencioso.

| Item | Como ficou |
|---|---|
| Pacote com teams | Papéis criados sem time próprio (uma linha serve todos); a atribuição é que carrega o time. O resolver de time do pacote aponta para o `TenantManager`. |
| Papéis | `owner`, `admin`, `member` + **`billing`** (acesso à cobrança sem acesso ao time). Seed idempotente que **sincroniza** em vez de acrescentar. |
| `super-admin` | Coluna no usuário, não papel do pacote — ver abaixo. `Gate::before` devolve `null`, não `false`, para os demais. |
| Painel `/admin` | Fechado por regra (`canAccessPanel` exige super-admin), não mais pelo fallback de ambiente do Filament. Fecha a pendência da Fase 0. |
| Auditoria | Lista curta e fechada de eventos que movem poder entre pessoas, em log próprio. |
| Impersonation | Trilha nas duas pontas + banner fixo que não fecha. Recusa aninhar, recusa a si mesmo e recusa outro super-admin. |

**Onde o pacote não coube.** O modo de times coloca o `team_id` **dentro da chave primária** da tabela de atribuições, então concessão sem time não tem onde existir — e `super-admin` é exatamente isso. Dobrar a chave primária do pacote seria brigar com a ferramenta; o papel virou coluna no usuário. Pelo mesmo motivo o seeder não cria a linha de `super-admin`: seria definição que ninguém poderia receber.

**Sobre o critério "nenhuma rota sem policy":** não há como detectar por inspeção que uma rota autoriza direito. O teste faz a versão honesta — toda rota autenticada precisa estar listada com o que a autoriza, e rota nova não classificada reprova o build. Escrever esse teste revelou que rotas de convidado estavam sendo contadas como autenticadas (`RedirectIfAuthenticated` contém "Authenticate"): eram 31, não 45.

**Correção de um erro da Fase 1.** O commit `30a0970` restaura o `require` do `laravel/socialite`, que eu havia desfeito sem perceber ao rodar `git checkout composer.json` durante a depuração do `memory_limit`. A suíte seguia verde porque o pacote continuava no `vendor/`; um `composer install` limpo teria quebrado desde então.

**Para a Fase 4:** o painel Filament já está restrito a super-admin e a impersonation já existe — os dois pré-requisitos que o plano previa construir lá.

---

### Fase 4 — Painel administrativo (Filament 5) *(2–3 dias)*

- Painel `/admin` restrito a `super-admin`
- Resources: Users, Teams, Subscriptions, Invitations, Activity Log, Failed Jobs
- Widgets: MRR, churn, signups (7/30/90d), teams ativos
- Ações em massa, export CSV, filtros salvos
- Plugin `filament/spatie-laravel-media-library-plugin` para uploads
- **Decisão a tomar na fase:** painel do tenant também em Filament (`/app`) ou 100% Livewire?
  → Recomendação: **Livewire+Flux no app do usuário** (controle de UX e branding), **Filament só no admin interno**. Evita a estética Filament vazar para o produto.

**Critério de aceite:** admin consegue investigar e resolver os 5 tickets de suporte mais comuns sem abrir o banco.

#### ✅ Fase 4 — CONCLUÍDA (com dois itens adiados)

`composer check` sai com **exit 0**: **236 testes** (1 pulado), 654 asserções, PHPStan `max` sem erros e 100% de type coverage.

O painel é desenhado em torno do balcão de suporte, não dos models. As colunas e ações são as respostas às perguntas que o suporte recebe.

| Item | Como ficou |
|---|---|
| Painel `/admin` restrito a `super-admin` | ✅ já entregue na Fase 3 |
| Resources | Usuários, Times, Convites, Trilha de auditoria, Jobs falhos |
| Widgets | Signups 7/30/90d e times ativos |
| Ações em massa e export CSV | Export transmite a seleção, sem exigir fila |
| **Subscriptions** | **Adiado** — depende de billing (Fase 5) |
| **MRR e churn** | **Adiados** — mesma razão |
| **Plugin de media library** | **Não instalado** — o avatar já usa storage direto; o plugin serve a formulários do Filament, e não há formulário de escrita no painel |

**Três decisões de projeto:**

1. **Usuários e Times são leitura e ação, sem criar nem editar.** Um operador que pudesse reescrever o e-mail de alguém tomaria a conta sem que a trilha mostrasse isso como uma tomada de conta.
2. **Excluídos aparecem por padrão.** "Excluí minha conta sem querer" é pedido de suporte, e não se responde numa lista que esconde a conta procurada.
3. **A trilha de auditoria não tem criar, editar nem apagar.** A primeira pergunta que se faz a um log é se ele poderia ter sido alterado.

**Sobre MRR e churn:** mostrá-los zerados seria pior que não mostrar. Painel que informa número inventado ensina a não confiar nos verdadeiros. Entram na Fase 5, junto com o resource de Subscriptions.

**A decisão que o plano pedia para tomar aqui** — painel do tenant em Filament ou 100% Livewire — fica como a recomendação original: **Livewire + Flux no app do usuário, Filament só no admin interno**. O app já está inteiro em Livewire 4 e a estética do Filament não vaza para o produto.

**O botão de impersonation entrou aqui.** A funcionalidade existia desde a Fase 3 com rota, trilha e banner, mas sem lugar de onde começar.

---

### Fase 5 — Billing (Cashier 16 + Stripe) *(3 dias)*

- Cashier no model `Team` (não `User`)
- Planos em config, checkout via Stripe Checkout, portal do cliente
- Trial de 14 dias sem cartão
- Webhooks: `subscription.updated/deleted`, `invoice.payment_failed`, dunning
- **Pennant** para feature flags atreladas ao plano
- Limites por plano (seats, projetos, storage) com `TeamLimits` + middleware `EnsureWithinLimits`
- Página de billing: plano atual, uso vs limite, faturas, upgrade/downgrade com proração
- Estados de borda: pagamento falho → grace period → suspensão → readonly

**Critério de aceite:** todos os webhooks testados com fixtures do Stripe; ciclo trial → assinatura → falha → cancelamento coberto em teste.

#### ✅ Fase 5 — CONCLUÍDA (com uma verificação pendente)

`composer check` sai com **exit 0**: **268 testes** (1 pulado), 719 asserções, PHPStan `max` sem erros e 100% de type coverage.

| Item | Como ficou |
|---|---|
| Cashier no `Team` | ✅ colunas em `teams`; quem paga é a organização |
| Planos em config, checkout e portal | ✅ hospedados na Stripe — dado de cartão não chega na aplicação |
| Teste de 14 dias sem cartão | ✅ |
| Webhooks + dunning | ✅ incluindo `invoice.payment_failed`, que o Cashier não trata |
| Pennant atrelado ao plano | ✅ |
| Limites por plano + middleware | ✅ |
| Página de cobrança | ✅ plano, uso vs limite, troca de plano |
| Estados de borda | ✅ falha → graça → suspensão → readonly |
| Resource de Subscriptions + MRR/churn | ✅ fecha as pendências da Fase 4 |

**O `TeamBilling` é o único lugar que lê estado de cobrança.** O Cashier responde com um punhado de booleanos que se sobrepõem, e lê-los em cada tela é como duas telas passam a discordar sobre a mesma conta. Quatro regras fixadas em teste: cancelar não encerra o período pago; cobrança falhando mantém acesso enquanto a Stripe tenta; assinatura encerrada volta ao plano padrão; e "sem assinatura" não é "sem limite".

**Duas armadilhas do Cashier**, ambas com o motivo escrito no código:

1. `onTrial()` só consulta o teste sem cartão quando é chamado **sem argumento nenhum**. Informar o tipo desliga a checagem — e o teste desta fase é sem cartão.
2. A migration publicada cria `user_id` porque o padrão do pacote é o model `User`; com `Team` o Cashier deriva `team_id`.

**Suspensão recusa dois casos de propósito:** time que nunca assinou não é suspenso (está no gratuito, fazendo o que tem direito), e as rotas de cobrança passam sempre — suspensão que bloqueia a página onde se pagaria é suspensão que ninguém levanta.

**Verificação contra a Stripe: fechada.** Com as chaves de teste configuradas, o checkout foi exercitado de verdade — sessão criada com preço real, cliente Stripe gerado para o time — e o portal devolveu URL hospedada. Isso virou `StripeIntegrationTest`, que é **opt-in** (`STRIPE_INTEGRATION=true`): suíte que chama provedor de pagamento em silêncio quebra quando a rede quebra e deixa objetos na conta. Sem a variável, a suíte segue offline; com ela, os clientes criados são apagados no fim.

A assinatura do webhook também passou a ser verificada, e isso não precisa da Stripe: o algoritmo é público e o segredo é nosso. `WebhookSignatureTest` cobre payload sem assinatura, assinado com segredo errado, fora da tolerância de tempo e assinatura pertencente a outro corpo — o endpoint não tem sessão nem cookie, então a assinatura é o que faz o papel de autenticação.

> ⚠️ **Uma diferença a observar:** o valor exibido de cada plano vem do `config/billing.php`, e o valor cobrado vem do preço na Stripe. São duas fontes. Quem as concilia é o `test_every_plan_charges_what_it_advertises`, que compara valor e moeda — mas ele é opt-in, então o CI não o roda e um reajuste feito só na Stripe continua passando despercebido até alguém pedir a verificação. Rodado de novo na Fase 10: passa.

---

### Fase 6 — Assíncrono e tempo real *(2 dias)*

- Redis + **Horizon** (tags por tenant, supervisores por prioridade)
- Convenções de job: `ShouldQueue`, `$tries`, `backoff` exponencial, `ShouldBeUnique`, `middleware(RateLimited)`
- Batches e chains com exemplo real (import CSV com progresso)
- Scheduler com `withoutOverlapping` + health check externo
- **Reverb** + notificações in-app em tempo real (Livewire 4 + Echo)
- Notificações: mail (Markdown), database, broadcast; preferências por usuário

**Critério de aceite:** Horizon processando; job falho vai para `failed_jobs` e alerta; notificação chega em tempo real com dois navegadores abertos.

#### ✅ Fase 6 — CONCLUÍDA (com uma verificação pendente)

`composer check` sai com **exit 0**: **314 testes** (5 pulados), 788 asserções, PHPStan `max` sem erros e 100% de type coverage.

| Item | Como ficou |
|---|---|
| Horizon | Dois supervisores por urgência; cadeia de tempo ordenada e testada; dashboard exige super-admin |
| Convenções de job | `TenantJob` com tenant que atravessa a fila, retry com espera crescente e desistência |
| Batches e chains | Import de convites em pedaços, tolerante a falha, cancelável |
| Scheduler | `withoutOverlapping` + heartbeat externo configurável |
| Reverb | Canais escopados por time, com canal de presença |
| Notificações | Mail (Markdown), database e broadcast, com preferências por usuário |

**A decisão que define a fase:** job enfileirado roda sem requisição, sem sessão e sem usuário — o `TenantManager` não tem de onde resolver. Combinado com o escopo que falha fechado, **um job que esquece o tenant não lê as linhas do time errado: não lê nada**, e silenciosamente não faz nada. Esse é o modo de falha que parece "não havia trabalho", e é por isso que o time viaja junto com o job.

**Autorização de canal é fronteira de tenant.** Canal de time que aceite qualquer autenticado entrega em tempo real o que o escopo global impede de sair por consulta — e sem deixar rastro, porque não há query.

**Preferências de notificação são gravadas como o que a pessoa desligou.** Com a forma oposta, um tópico ou canal acrescentado depois começaria desligado para todo mundo. Duas coisas não desligam: o registro no app e o tópico de segurança.

**Quatro armadilhas, todas com o motivo escrito no código:**

1. Curinga de canal chega sempre como string; com `strict_types`, declarar `int` faz a autorização virar 403 **para todo mundo**.
2. Canais são registrados no driver ativo no boot — trocar o driver depois dá um broadcaster sem canal nenhum.
3. Evento de callback do scheduler não tem nome próprio: `name()` antes de `withoutOverlapping()`.
4. O canal `database` precisa da tabela de notificações; sem ela o insert aborta a transação e o erro aparece numa consulta seguinte, sem relação com a causa.

**Verificação de tempo real: fechada.** O `laravel-echo` e o `pusher-js` foram instalados, o Echo é inicializado em `resources/js/echo.js` e os assets compilam com ele. O trajeto completo foi exercitado com o servidor de verdade: `reverb:start` no ar, Horizon processando, e um cliente WebSocket real inscrito num canal privado recebendo a notificação publicada pelo PHP.

O que foi observado, em ordem: Horizon processou `MemberJoined` e em seguida `BroadcastNotificationCreated`, e o cliente recebeu o payload com título, `team_id`, `id` e `type` da notificação.

Duas coisas que só aparecem nesse teste:

1. **Notificação broadcast passa pela fila.** Com `QUEUE_CONNECTION=redis` e nenhum worker, a notificação fica parada e o cliente não recebe nada — sem erro em lugar nenhum. Quem verificar isso precisa do Horizon no ar.
2. **O Echo só conecta se houver `VITE_REVERB_APP_KEY`.** Sem isso ele ficaria tentando reconectar para sempre em segundo plano, e o erro apareceria no console de quem não pediu tempo real.

---

### Fase 7 — API pública *(2 dias)*

- `/api/v1` versionada, Sanctum com **tokens escopados por team + abilities**
- Gestão de tokens na UI (criar, nomear, escopar, revogar, last_used_at)
- `spatie/laravel-query-builder` para filtro/sort/include padronizados
- API Resources + paginação por cursor
- Rate limiting por token e por plano
- **Scramble** gerando OpenAPI automático em `/docs/api`
- Padrão de erro consistente (RFC 7807 / Problem Details)
- Webhooks de saída para o cliente, com assinatura HMAC e retry

**Critério de aceite:** OpenAPI válido; teste provando que token do team A não acessa recurso do team B.

#### ✅ Fase 7 — CONCLUÍDA

Tudo do escopo foi entregue. O OpenAPI 3.1 é gerado pelo Scramble e descreve seis rotas; o critério de aceite é o `ApiTokenTest`, que prova que um token do time A não alcança recurso do time B mesmo quando o dono pertence aos dois times.

**O tenant da API vem do token e de mais nada.** Deixá-lo vir do "time atual" do dono faria uma chamada de API responder diferente conforme o que essa pessoa clicou no navegador por último. A habilidade do token é teto, não permissão: `ability:write` diz o que a credencial pode tentar, a policy continua dizendo o que a pessoa pode fazer, e as duas checagens rodam.

**Webhooks de saída.** Assinatura HMAC com o timestamp assinado junto ao corpo — assinar só o corpo produz assinatura que vale para sempre, e quem capturar uma entrega pode reenviá-la um ano depois. O segredo é criptografado e não hasheado, ao contrário do token de API, porque o cliente precisa lê-lo mais de uma vez. Um job por endpoint, entrega retida até o commit, e desligamento automático depois de dez falhas seguidas.

Quatro achados desta fase, dois deles em testes meus que passavam sem testar nada:

1. **`$team->members()->getQuery()` perde o join do pivô** e listava todos os usuários da base. Era vazamento entre clientes. O meu teste de vazamento usava `assertJsonMissing` e não pegou: ele procurava um fragmento em vez de comparar a lista inteira. Trocado por asserção sobre o conjunto exato.
2. **O teste de revogação de token passava sem depender do token.** O `actingAs` do `setUp` autentica por sessão e o `auth:sanctum` aceita, então aquelas asserções passariam com qualquer token, inclusive um revogado. Corrigido com `forgetGuards()`.
3. **O dispatcher de webhook lia o tenant atual** para achar os endpoints. Quem aceita convite do time B está navegando como time A naquele instante, então o evento de B ia para o endpoint de A. O time virou parâmetro.
4. **A ligação padrão de `TeamInvitation` é por `code`**, que é a credencial de entrada no time. A rota de cancelamento pela API exigiria mandá-lo na URL, ou seja, no log de acesso. Passou a ligar por id.

**Emitir evento a partir do model tem uma armadilha:** delete de query apaga a linha sem construir model nenhum, então nenhum evento dispara e o cliente nunca é avisado. As duas remoções de membro passaram a apagar o model, e há teste segurando isso.

**Peculiaridade da ligação de rota:** `GET` devolvendo 201. O Laravel infere o status de `wasRecentlyCreated`, o que faz o código de uma leitura depender de o model ter sido criado antes no mesmo processo. Onde importa, o status é explícito.

---

### Fase 8 — Observabilidade *(1–2 dias)*

- **Pulse** — slow queries, slow jobs, exceptions, uso por usuário
- **Telescope** — apenas local/staging, nunca produção
- Logs estruturados JSON com `team_id` + `request_id` em todo contexto
- Sentry (ou equivalente) para exceptions, com release tracking
- Health check `/up` estendido: DB, Redis, fila, storage
- **Alternativa a avaliar:** `laravel/nightwatch` (1.28.4) — APM first-party, pago, substitui Pulse+Sentry com menos peças

**Critério de aceite:** exception em produção gera alerta em < 1 min com contexto de tenant.

#### ✅ Fase 8 — CONCLUÍDA

Stack escolhido: **Pulse + Sentry**, como no plano. O Nightwatch foi descartado por ser pago desde o primeiro dia e hospedado fora — um boilerplate que exige conta paga para rodar completo não serve de template. O critério de aceite é o `SentryContextTest`: a exceção sai etiquetada com o time e com o mesmo identificador de requisição que está no log.

**Rastro.** Toda requisição ganha um identificador, devolvido em `X-Request-Id` e aceito quando vem de fora, para que um rastro começado no balanceador não seja renomeado na nossa porta. O time é publicado pelo `TenantManager`, não por um middleware: o tenant não é definido só por requisição — um job na fila o restaura, o painel do operador o troca, e um relatório noturno passa por todos os times em sequência. Usa `Context` e não `Log::withContext` porque o `Context` sobrevive à passagem para a fila.

**`/up`.** O embutido só informa que o PHP subiu, que é a coisa menos útil de saber: uma instância sem banco sobe, responde 200 e continua recebendo tráfego. Agora verifica banco, Redis, tamanho da fila e escrita em disco, e a mensagem diz qual das quatro caiu. A sonda de disco escreve e lê de volta, porque listar arquivos funciona num disco cheio e num montado como somente leitura.

**Telescope** é dependência de desenvolvimento, sai do package discovery, é registrado à mão só em local, e a migração não cria tabela fora de local.

Três achados:

1. **O gate `viewTelescope` não fechava nada.** O `Gate::before` da aplicação libera tudo para super admin e passa por cima de qualquer definição — o comentário e o teste que eu tinha escrito afirmavam o contrário. O que protege são as três camadas acima, e é isso que o teste afirma agora.
2. **O instalador do Telescope reescreveu `bootstrap/providers.php`** em ordem alfabética, sem strict types e com o `CoreServiceProvider` por último, que é quem registra os providers dos módulos. Era exatamente o que o comentário no arquivo avisava. Terceira vez que um instalador faz isso.
3. **O stub do `TelescopeServiceProvider` esconde parâmetros sensíveis só fora de local**, o que deixaria senha e cookie de sessão legíveis no painel. Passou a esconder sempre.

**Pendência registrada aqui, fechada na Fase 10:** o `/up` respondia 500 nesta máquina, e eu atribuí a configuração do contêiner. Não era. O `artisan serve` só repassa ao processo que atende requisições uma lista fixa de variáveis, e os hosts que o `compose.yaml` sobrescreve não estavam nela — chegavam à CLI e não ao HTTP. Diagnóstico errado registrado como ambiente por quatro fases.

---

### Fase 9 — Testes e CI/CD *(2 dias)*

- Pest: unit, feature, **browser** (Pest 4 tem browser testing nativo — dispensa Dusk)
- Meta: **cobertura ≥ 85%**, **type coverage ≥ 95%**
- Mutation testing nos módulos críticos (tenancy, billing, autorização)
- Factories e seeders realistas; `RefreshDatabase` com transações
- GitHub Actions:
  - matriz PHP 8.3 / 8.4
  - jobs paralelos: lint, static, test, browser, security
  - `composer audit` + `npm audit`
  - build de assets
- Dependabot / Renovate
- Deploy automático em staging no merge para `main`

**Critério de aceite:** CI completo em < 6 min; PR não mergeia com CI vermelho.

#### ✅ Fase 9 — CONCLUÍDA (com um item impossível e um não exercitado)

`composer check` sai com **exit 0**: **411 testes** (5 pulados), 978 asserções, PHPStan `max` sem erros e 100% de type coverage. Mais 5 testes de browser, que rodam fora dessa suíte.

**O CI virou cinco jobs paralelos** — lint, static, test, browser e security — no lugar do job único que rodava `composer check` em série. Quem quebrou o Pint descobre em um minuto em vez de esperar a suíte. Cada job paga um `composer install` próprio; não há cache porque `actions/cache` precisaria entrar sem SHA fixado, e este repositório fixa toda action por SHA. `ci:check` foi removido: script de CI que o CI não chama é script que mente.

**A matriz PHP 8.3/8.4 não é possível.** A árvore instalada exige 8.4.1+ (`symfony/*` v8.1, `spatie/laravel-activitylog` 5), então `composer install` em 8.3 falha. O `composer.json` declarava `"php": "^8.3"` — uma restrição que não correspondia ao que o lock instala. Corrigida para `^8.4`.

Isso teve uma consequência que não estava no plano: o Rector usa a restrição do `composer.json` como teto e vinha ignorando as regras de PHP 8.4 apesar do `withPhpSets(php84: true)`. Com `^8.4` ele destravou 7 arquivos — `array_any` no lugar de `foreach`, `new X()->método()` sem parênteses, `escape:` explícito no `fputcsv`. Aplicados, porque a configuração do Rector é do projeto e estava sendo obedecida pela metade.

**Cobertura: 90,8%**, travada em `--min=85`. Type coverage já estava em 99. O gate roda com pcov, não xdebug: o que o job precisa é do número, não de depuração.

**Browser.** Pest 4 com Playwright e só o Chromium. Cinco testes: fumaça nas quatro páginas públicas, login pelo formulário (certo e errado) e criação de time pelo modal (sucesso e validação). A suíte `Browser` fica fora da padrão no `phpunit.xml` — exige navegador instalado — e roda com `composer test:browser`.

Dois achados dos red-checks, ambos verificados injetando o caso:

1. **`assertNoSmoke()` não pega `console.error`.** Pega exceção não capturada e `console.log`. Quem quiser barrar `console.error` precisa de asserção própria.
2. **O Playwright espera indefinidamente por um elemento invisível.** Um modal que parasse de abrir travaria o job em vez de falhar. Por isso todo teste que abre modal tem um `assertSee` de guarda antes de digitar.

**Mutation testing** em tenancy, billing e autorização: **MSI 87,39%** (201 de 230 mutantes mortos), travado em `--min=87`. Roda em agendamento noturno, não no CI de PR — a medição leva 26 minutos, e sinal que atrasa todo PR vira sinal ignorado.

A primeira medição deu 82,88% e apontou um buraco real: **9 mutantes sobreviviam no `EnsureTeamMembership`**, a fronteira de time nas rotas web. Trocar o `||` do `abort_if` por `&&` deixava um não-membro entrar e nenhum teste reclamava — o que existia eram testes do componente Livewire, que roda depois do middleware e não passa por ele. O `TeamMembershipMiddlewareTest` fecha os 9: o arquivo está em 100%.

Dois achados vieram junto:

- **O parâmetro de papel mínimo do middleware não é usado por nenhuma rota.** As rotas do teste existem para exercitá-lo; código que só é alcançado quando alguém decidir usá-lo quebra sem aviso.
- **O `?->` do `SetTenantFromToken` é inalcançável.** O `auth:sanctum` roda antes por prioridade de middleware, então `$request->user()` nunca é nulo ali. É mutante equivalente, não falta de teste.

Os 29 sobreviventes restantes estão em `Impersonation` (8), `TeamBilling` (5), `EnsureTeamIsNotSuspended` (4), `SubscriptionStatus` (3), `Plan` (3), `EnsureTokenAbility` (3), `SetTenantFromToken` (2) e `TenantManager` (1). O `--min=87` é catraca: prende o número de hoje e falha se ele cair.

> **Fechado depois da Fase 10.** Os 29 viraram 5, e os 5 são equivalentes — três `?->` sobre `$request->user()` e `$request->route()` em middlewares que rodam depois do `auth` ou só existem dentro de uma rota, e dois que trocam a string vazia do `Plan` por um texto qualquer, que nenhum plano pode carregar depois da normalização. **MSI 97,87%** (230 de 235), catraca em `--min=97`. O que os outros 24 escondiam está no corpo dos commits `ab6798c` e no seguinte.

**Dependabot** passou a cobrir composer e npm além das actions. Minor e patch vêm agrupados — um PR por pacote em um projeto com ~60 dependências vira ruído que ninguém revisa. Major fica fora do grupo, porque é o que costuma quebrar e quebrar sozinho é o que torna o PR legível.

**Deploy em staging: Laravel Cloud**, fechando a decisão pendente da §10. O `deploy-staging.yml` dispara por `workflow_run` depois do `ci` verde e chama o deploy hook. Tem duas travas: o resultado do CI e a variável de repositório `DEPLOY_STAGING`. Enquanto não existir ambiente de staging, o workflow fica parado em vez de falhar toda noite por um segredo que ninguém cadastrou.

**Não exercitado:** este repositório não tem remote nem ambiente de staging. Nenhum dos quatro workflows rodou de verdade — o que está verificado é o que cada job chama, rodado à mão. O critério de aceite "CI completo em < 6 min" não é verificável daqui; os jobs foram desenhados para caber, mas o número só existe quando houver uma execução.

---

### Fase 10 — DX e documentação *(2 dias)*

- **CLAUDE.md** — convenções, quando promover para módulo, como adicionar model tenant-aware
- README com setup em ≤ 5 comandos
- ADRs (`docs/adr/`) das decisões estruturais: tenancy single-DB, Livewire vs Inertia, Filament só no admin
- Generators customizados: `make:tenant-model`, `make:action`, `make:domain`
- Seeder de demo: 3 teams, 10 usuários, assinaturas em estados variados
- Script `bin/setup` idempotente
- Checklist de "como remover o que não vou usar" (billing, API, Reverb) — boilerplate bom é fácil de podar

**Critério de aceite:** dev novo tem o app rodando com dados de demo em < 15 min, sem ajuda.

#### ✅ Fase 10 — CONCLUÍDA

`composer check` sai com **exit 0**: **428 testes** (5 pulados), PHPStan `max` sem erros e 100% de type coverage. Mais 5 testes de browser.

**`bin/setup` é o caminho de entrada**, e o requisito difícil dele não é funcionar: é poder rodar de novo. Cada passo verifica antes de escrever, e o que não pode ser repetido — gerar a `APP_KEY`, que tornaria ilegível tudo que está cifrado no banco — é o mais protegido. Verificado rodando duas vezes seguidas: mesma chave, mesmas contagens, exit 0 nas duas. O CI continua com `composer setup`, porque lá os serviços vêm do runner e o checkout é sempre novo.

**O README fecha em três comandos** (clone, `bin/setup`, `composer dev`) e tem teste: cada `composer <script>` citado existe no `composer.json`, cada `php artisan <comando>` está registrado, cada link aponta para arquivo que existe, e o `bin/setup` está executável. Documentação não se testa lendo; o que apodrece sem avisar é comando renomeado e link quebrado.

**A pendência da Fase 8 fechou, e não era o que parecia.** O contêiner respondia 500 em `/` e `/up` enquanto a CLI dentro dele alcançava o Postgres — o que lê como problema de rede e não é. O `artisan serve` só repassa ao processo que atende requisições uma lista fixa de variáveis; o resto esse processo relê do `.env`, que aponta para `127.0.0.1`. Os hosts que o `compose.yaml` sobrescreve chegavam à CLI e não ao HTTP. Corrigido declarando as variáveis no `AppServiceProvider`, com teste que compara o bloco `environment` do `compose.yaml` com a constante — override novo lá sem entrada aqui falha em vez de chegar pela metade. Agora `/`, `/login` e `/up` respondem 200.

**Seeder de demo:** 3 times com assinatura ativa, em teste e inadimplente, 10 pessoas, uma delas em dois times com papel diferente em cada, e dois convites pendentes. Tudo em upsert, porque o `bin/setup` o executa.

Um achado no caminho: o `DatabaseSeeder` vinha com `WithoutModelEvents`, e neste projeto os eventos de model carregam correção — slug do time, código do convite e carimbo de tenant nascem em `creating`. Semear com eles desligados estourava na restrição de not-null do código do convite; o caso pior seria gravar linha sem tenant, invisível para todo mundo depois. O trait saiu, e o teste que percorre o `DatabaseSeeder` inteiro é o que impede ele de voltar.

**Geradores:** `make:action` e `make:tenant-model`. O `make:domain` do plano já existia como `make:module` desde a fase 0. O `make:tenant-model` escreve os três arquivos que precisam concordar — model com o trait, migration com `team_id` e índice, factory com o time explícito —, porque `make:model -mf` produz model sem tenant e migration sem coluna, e os dois erros são silenciosos.

**Seis ADRs** em `docs/adr/`, cada um com o que foi descartado junto: a alternativa recusada é a parte que se perde primeiro. O **CLAUDE.md** ganhou as convenções do projeto fora do bloco do Boost — verificado no `GuidelineWriter`, que substitui só o que está entre as marcas. E `docs/poda.md` diz como remover cobrança, API, tempo real, webhooks, painel, observabilidade, busca e login social, com a lista de arquivos de cada um.

**Sobre o critério de aceite:** os três comandos foram exercitados nesta máquina, com o segundo `bin/setup` levando pouco mais de um minuto. O que não dá para verificar daqui é a parte que importa — alguém que nunca viu o projeto conseguir sozinho. Isso só se sabe olhando a primeira pessoa tentar.

**Fica aberto:** o `docs/poda.md` é instrução escrita, não testada. Cada poda de verdade vai encontrar resto que a lista não previu; a defesa é o `composer check` depois de cada uma, que é o que o próprio documento manda fazer.

---

### Depois do plano — Fase 0 da cobrança: ligar o plano ao produto

O levantamento que originou este trabalho achou um tema, não itens soltos: a
máquina de cobrar por recurso estava construída e não estava plugada em nada.
A API não perguntava o plano, o teto de membros não era verificado em lugar
nenhum, e o catálogo vendia SSO e trilha de auditoria que o produto não entrega.

`composer check` sai com **exit 0**: **483 testes** (5 pulados), 1140
asserções, Pint, Rector e PHPStan `max` sem erros, Peck sem achados e **100%**
de type coverage. Mais **5 testes de browser** (`composer test:browser`), que
seguem fora dessa suíte e cobrem o convite de time pela tela — o time do teste
é pessoal, com um membro só, e não esbarra no teto do plano gratuito.

No contêiner, com driver de cobertura: **92,3%** de cobertura (`composer
test:coverage`, piso `--min=85`) e **MSI 98,37%** — 242 de 246 mutantes mortos
(`composer test:mutate`, piso `--min=97`), em 25 minutos e 54 segundos. Os
quatro sobreviventes são os mesmos mutantes equivalentes já registrados no
fechamento da Fase 9: três trocam `?->` por `->` em `$request->user()` e
`$request->route()` dentro de middlewares que só rodam depois de `auth:sanctum`
ou só existem dentro de uma rota nomeada, e um troca a string vazia do
`Plan::forPriceId()` por texto qualquer, que nenhum plano normalizado carrega.
O MSI subiu de 97,87% para 98,37% — a remoção do `EnsureWithinPlanLimits` tirou
da medição um arquivo que estava em 0%, e o `PlanFeatureTest` e o
`ResourceCountersTest` novos passaram a cobrir código que antes só existia sem
teste.

**A catraca ficou em `--min=97`, não subiu para 98.** 98,37% sobre 246
mutantes é uma margem menor que o peso de um único mutante (1/246 ≈ 0,41 p.p.):
um sobrevivente a mais na próxima execução — e mutation testing varia entre
execuções — já derruba o número para 241/246 = 97,97%, abaixo de 98. Colar a
catraca no valor exato medido transforma essa variação em CI vermelho sem
regressão nenhuma, e um gate que falha sem motivo é um gate que a próxima
pessoa aprende a contornar baixando o número, em vez de investigar. Subir
exige mais do que uma medição boa: exige medições repetidas mostrando o mesmo
patamar, o que uma execução isolada não estabelece — um ponto não é tendência.

**E então a catraca desceu para `--min=95`, por aritmética e não por
regressão.** A revisão final apontou que a lista de classes medidas tinha
perdido o ponto de decisão: a verificação de limite mudou de middleware para
`App\Actions\Teams`, que estava fora da medição — parte da subida para 98,37%
era um arquivo saindo do denominador, não teste novo. Com `InviteTeamMember` e
`ImportInvitations` acrescentados, a medição passou de 246 para **286 mutantes
em 18 arquivos**, e o resultado foi **MSI 96,50%** (276 mortos, 10
sobreviventes).

Os dez sobreviventes são **todos equivalentes**, conferidos um a um: os quatro
já conhecidos da Fase 9, mais o `?? 0` de `limit('members')` nas duas actions —
ramo que só é alcançado quando o limite é nulo, e limite nulo significa
ilimitado, o que faz `withinLimit()` devolver verdadeiro e nunca chegar ao
`throw` —, mais o `array_values()` de `seatsAskedBy()`, cujo retorno só é
consumido por um `count()` e portanto não enxerga chave nenhuma, e o `true` de
um array usado como conjunto de chaves, cujo valor nunca é lido.

> Uma correção que vale registrar sobre este parágrafo: a primeira versão dele
> justificava o `array_values()` dizendo que o `array_chunk()` seguinte tolera
> chaves não sequenciais. A conclusão estava certa e o mecanismo errado — aquele
> retorno nunca chega ao `array_chunk()`, que opera sobre outra variável. Quem
> apontou foi a revisão dos últimos três commits, e o erro é instrutivo: eu
> afirmei ter conferido os dez sobreviventes "um a um", e num deles conferi o
> resultado sem conferir o caminho.

Ou seja: **96,50% é o teto alcançável dessas 18 classes**, não uma dívida. A
catraca em 95 deixa folga de quatro mutantes; deixá-la em 97 seria um CI
vermelho todo dia por conta do denominador ter mudado. O número menor é o
honesto — o que piorou não foi a suíte, foi o que ela passou a admitir medir.

O caminho para essa medição fechou duas lacunas constrangedoras que só
apareceram por causa dela: **nenhum teste afirmava que o convite nasce com
prazo de expiração, nem que o e-mail é enviado** — as duas coisas que o
docblock da `InviteTeamMember` diz que ela existe para manter iguais entre os
três chamadores. A classe centralizava exatamente o que não era verificado.

Os achados que valem para além desta fase:

1. **O teto de membros não era verificado em lugar nenhum.**
   `TeamBilling::withinLimit()` só era chamado pelo middleware
   `EnsureWithinPlanLimits`, e esse middleware não estava registrado em rota
   alguma — o convite pela tela passa pela rota do Livewire, que um middleware
   de rota nunca cobriria. Os dois apareciam com 0% de cobertura, não por
   falta de teste: por nada os executar. Um time no plano gratuito, teto de
   três, convidava cinquenta. A verificação foi para a action
   `InviteTeamMember`, o único ponto por onde os três chamadores — modal,
   importação em lote e API — passam (commit `42676c2`).
2. **A API respondia 500 para um teto que funcionava como projetado.**
   `PlanLimitException` estende `RuntimeException`, e o `ApiProblem` casa por
   tipo de exceção: sem um `match` próprio, ela caía no `default` e virava
   `server_error` — a suspensão por inadimplência, um estado equivalente, já
   respondia 402. Ninguém tinha visto porque a exceção nunca era lançada antes
   do achado anterior ser corrigido. Fechado no mesmo commit `42676c2`, com o
   mesmo 402 que a suspensão usa.
3. **Esconder o formulário de criar token com `@if` não protegia nada.** A
   tela de tokens escondia o formulário de quem estava num plano sem API, mas
   a ação `createToken` do componente Livewire continua alcançável direto pelo
   protocolo do Livewire, pulando o markup — e um time no plano gratuito
   criava token normalmente. Autorização não mora na view; a ação passou a
   repetir a mesma checagem que a tela usa para decidir o que mostrar
   (commit `5c92945`).
4. **O job da importação em lote duplicava a `InviteTeamMember` quase linha
   por linha** — criação da invitation, prazo de expiração e disparo do
   e-mail incluídos —, enquanto o docblock da action já afirmava ser o único
   lugar, "porque agora são três chamadores". A duplicata sumiu junto com a
   verificação de teto do achado 1: `ImportInvitationChunk` passou a chamar
   `InviteTeamMember::handle()` por e-mail, e o lote inteiro é verificado
   contra o teto antes de qualquer job ser despachado (commits `42676c2` e
   `45561cf`).
5. **`team_id` está fora do `$fillable` que `ApiToken` herda do
   `PersonalAccessToken` do Sanctum**, então `$token->update(['team_id' =>
   ...])` falharia em silêncio — o Eloquent descarta atributo não listado sem
   avisar nada. A tela de tokens já escreve o time por
   `ApiToken::query()->whereKey(...)->update(...)`, que passa por fora do
   Eloquent e do `$fillable`; registrado aqui porque é exatamente o tipo de
   armadilha que um `update()` direto no model reintroduziria sem teste
   nenhum acusar, até o token falhar na primeira chamada à API.

O catálogo ficou enxuto: `config/billing.php` só anuncia o limite de
`members`, que tem contador registrado, e o recurso `api`, que o middleware
`plan-feature` de fato verifica. SSO e trilha de auditoria saíram do plano
`config` — o primeiro não existe no produto, o segundo só aparece no painel do
operador.

---

### Depois do plano — a primeira máquina limpa de verdade

O fechamento da Fase 10 dizia que o critério de aceite — alguém que nunca viu o
projeto conseguir sozinho — "só se sabe olhando a primeira pessoa tentar". A
primeira tentativa num checkout sem `vendor/`, sem `node_modules` e sem `.env`
aconteceu, e o `bin/setup` **não passou**. Três achados, os três corrigidos com
teste em `SetupScriptTest`:

1. **A ordem dos dois primeiros passos estava invertida.** O `compose.yaml`
   monta `./vendor/laravel/sail/database/pgsql/create-testing-database.sql`, e o
   script subia o Compose antes do `composer install`. Num clone limpo esse
   caminho não existe e quem o cria é o Docker — **como root**. O
   `composer install` seguinte morre em `vendor/pestphp does not exist and could
   not be created`, e sair desse estado exige um contêiner só para apagar o que
   ficou, porque o usuário não tem permissão sobre o que o daemon criou. O
   `composer install` passou para antes de subir os serviços. O teste ancora no
   `compose.yaml`: se ele deixar de ler de `vendor/`, a regra se anuncia
   obsoleta em vez de virar cerimônia sem motivo.
2. **O `aspell` não era conferido.** O `bin/setup` exigia php, composer, npm e
   docker, mas o `composer check` — que é como este projeto define "terminei" —
   termina no Peck, que não roda sem o aspell e o dicionário `en`. O check
   abortava antes de chegar aos testes, numa máquina onde tudo o mais estava
   certo. O CI já instalava os dois (`tests.yml`); a máquina do dev, não.
3. **A mensagem final mentia sobre as portas.** Ela imprimia `localhost`,
   `:8025` e `:8900` fixos, ignorando os `FORWARD_*` do `.env`. Quem remapeou
   por conflito com outro projeto — situação comum, e foi o que aconteceu aqui:
   6379, 1025/8025, 7700, 80 e 5173 já estavam ocupadas — recebe endereços que
   não respondem e vai procurar o defeito no contêiner, que está certo.

Um quarto, menor, apareceu junto: o `package.json` não tinha `name`, então o npm
o derivava do nome da pasta e reescrevia o `package-lock.json` a cada
`npm install`. Sujeira recorrente no diff que ninguém escolheu.

**O que isso diz sobre o critério de aceite:** ele não estava verificado, estava
suposto. As duas execuções que fecharam a Fase 10 rodaram numa máquina que já
tinha `vendor/` — e é justamente o que não existe na primeira vez que alguém
tenta.

---

## 5. Cronograma

| Fase | Dias | Acumulado |
|---|---|---|
| 0 — Fundação | 1–2 | 2 |
| 1 — Auth | 2 | 4 |
| 2 — Tenancy | 3 | 7 |
| 3 — RBAC | 2 | 9 |
| 4 — Filament | 2–3 | 12 |
| 5 — Billing | 3 | 15 |
| 6 — Assíncrono | 2 | 17 |
| 7 — API | 2 | 19 |
| 8 — Observabilidade | 1–2 | 21 |
| 9 — Testes/CI | 2 | 23 |
| 10 — DX/Docs | 2 | **25** |

**~25 dias úteis (5 semanas)** para 1 dev full-time.

**MVP utilizável em 9 dias** (Fases 0–3): auth completa + multi-tenancy + RBAC. Já dá para iniciar produto real e adicionar billing depois.

---

## 6. Fora de escopo (deliberado)

- **Octane/FrankenPHP** — adicionar só quando houver problema de performance medido. Introduz classe inteira de bugs de estado compartilhado.
- **Scout/Meilisearch** — container já sobe no Sail, integração fica para quando houver busca real.
- **i18n completo** — estrutura de locale pronta na Fase 1, traduções ficam por conta do produto.
- **Laravel MCP** (expor o app como ferramenta de IA) — interessante, mas não é fundação.

---

## 7. Riscos

| Risco | Impacto | Mitigação |
|---|---|---|
| Vazamento entre tenants | **Crítico** | **Implementado na Fase 2** — escopo global fail-closed, regra de arquitetura, `tenant:audit` e suíte de isolamento. Continua crítico a cada model novo. |
| ~~Filament 5 + Livewire 4 no mesmo app~~ | ~~Médio~~ | **Fechado na Fase 0** — spike feito, convivência coberta por teste |
| ~~Flux Pro necessário para componentes-chave~~ | ~~Baixo~~ | **Mapeado na Fase 1** — `flux:select` com `variant="listbox"`/busca é Pro. Regra: assumir o free e verificar antes de usar variante |
| ~~Cashier + tenancy (assinatura no Team)~~ | ~~Médio~~ | **Fechado na Fase 5** — colunas do Cashier em `teams`, assinatura do time |
| Larastan max desde o início atrasa | Baixo | Aceitar; o custo de subir nível depois é maior |
| Boilerplate virar framework paralelo | Médio | Regra: nada de abstração sem 3 usos reais. **Revisada na Fase 10** — a regra está escrita no CLAUDE.md, e o único código de infraestrutura próprio continua sendo o sistema de módulos. |
| Duas fontes de verdade para papel | **Fechado na Fase 3** | A coluna `team_members.role` foi removida; o pacote de permissões é a única fonte das atribuições |

---

## 8. Comandos de bootstrap (Fase 0)

```bash
# 0. Node primeiro
nvm install 22 && nvm use 22

# 1. Projeto (confirmar flags com `laravel new --help`)
laravel new boilerplate-laravel

cd boilerplate-laravel

# 2. Infra local
php artisan sail:install --with=pgsql,redis,meilisearch,mailpit,minio
./vendor/bin/sail up -d

# 3. Qualidade
composer require --dev larastan/larastan rector/rector peckphp/peck \
    pestphp/pest-plugin-type-coverage driftingly/rector-laravel

# 4. Domínio
composer require spatie/laravel-permission spatie/laravel-data \
    spatie/laravel-activitylog laravel/fortify laravel/socialite

# 5. Admin
composer require filament/filament:"^5.0"
php artisan filament:install --panels

# 6. Boost (MCP com docs Laravel)
composer require --dev laravel/boost && php artisan boost:install
```

---

## 9. Ordem de decisão

Três decisões precisam sair **antes da Fase 0** porque mudam a fundação:

1. **PostgreSQL vs MySQL** → recomendação: PostgreSQL 17 (JSONB, CTEs, RLS como rede de segurança futura para tenancy)
2. **Deploy** → não definido; ver §10
3. **Flux Pro** → verificar se o design pretendido depende de componentes pagos

---

## 10. Decisão de deploy: Laravel Cloud *(fechada na Fase 9)*

Não foi escolhido na definição inicial. Opções:

| Opção | A favor | Contra |
|---|---|---|
| **Laravel Cloud** | Zero infra, Postgres serverless, filas e cron gerenciados, first-party | Menos controle, custo escala com uso |
| **Forge + Envoyer** | VPS próprio, custo previsível, zero-downtime | Você opera o servidor |
| **Docker + Kubernetes** | Portável | Overhead alto para boilerplate |

Recomendação: **Laravel Cloud** para o boilerplate (setup mais rápido, alinhado ao objetivo de agilidade), documentando o caminho Forge como alternativa. Afeta a Fase 9 (pipeline de deploy).

**Decidido na Fase 9: Laravel Cloud.** O `deploy-staging.yml` chama o deploy hook do ambiente. Trocar por Forge é trocar a URL do segredo — o gatilho é o mesmo POST.
