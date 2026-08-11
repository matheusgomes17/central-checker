# Boilerplate Laravel

Base para SaaS multi-tenant: autenticação completa, times, papéis e permissões
por time, cobrança por time, API pública versionada, filas, tempo real e painel
de operação. Feito para ser podado — o que o seu produto não usa sai fora, e o
[checklist de poda](docs/poda.md) diz como.

## O que precisa estar instalado

PHP 8.4, Composer, Node 22 e Docker. Os serviços — Postgres, Redis, Mailpit,
MinIO e Meilisearch — sobem em contêiner; a aplicação roda na sua máquina.

## Começando

```bash
git clone <url-do-repositório> boilerplate-laravel
cd boilerplate-laravel
./bin/setup
composer dev
```

`bin/setup` sobe os serviços, instala as dependências, migra, semeia dados de
demonstração e compila os assets. Pode rodar de novo quando quiser: ele verifica
antes de escrever e nunca troca a `APP_KEY` já existente.

`composer dev` sobe servidor, fila, logs e Vite juntos, em
<http://localhost:8000>.

### Entrando

Todos os usuários de demonstração usam a senha `password`:

| E-mail | Para ver |
|---|---|
| `ana@example.test` | Super admin, dona da Acme, com acesso ao `/admin` |
| `bruno@example.test` | Globex, assinatura em período de teste |
| `carla@example.test` | Initech inadimplente, para ver um time suspenso |
| `joao@example.test` | A mesma pessoa em dois times, com papel diferente em cada |

Outros endereços: <http://localhost:8025> (Mailpit, onde chegam os e-mails),
<http://localhost:8900> (console do MinIO, `sail` / `password`) e
<http://localhost/docs/api> (documentação da API).

## No dia a dia

| Comando | O que faz |
|---|---|
| `composer dev` | Servidor, fila, logs e Vite |
| `composer check` | Tudo que o CI checa, menos os testes de browser |
| `composer test` | Só a suíte |
| `composer test:browser` | Testes em navegador real; exige `npx playwright install chromium` uma vez |
| `composer test:coverage` | Suíte com o piso de cobertura de 85% |
| `composer test:mutate` | Mutation testing nos caminhos críticos |
| `composer lint` | Pint |
| `php artisan make:module` | Cria um módulo em `modules/` |
| `php artisan make:action` | Cria uma action |
| `php artisan make:tenant-model` | Cria model escopado por time, com migration e factory |
| `php artisan tenant:audit` | Lista models que deveriam ser escopados por time e não são |

## Como o projeto está organizado

```
app/            Domínio da aplicação: models, actions, policies, middleware
modules/        Módulos opcionais; Core é a fiação, o resto é seu
docs/adr/       Por que cada decisão estrutural foi tomada
tests/          Arch, Unit, Feature, Browser
```

O tenant é o **time**, não o usuário. Todo model de domínio nasce escopado por
time, e a regra de arquitetura falha se algum nascer sem. Se você vai
acrescentar um model, comece pelo [CLAUDE.md](CLAUDE.md) — ele descreve o
caminho curto e o que a suíte vai cobrar.

## Onde as decisões estão escritas

- [`docs/adr/`](docs/adr) — uma decisão estrutural por arquivo, com o que foi
  descartado junto
- [`PLANEJAMENTO.md`](PLANEJAMENTO.md) — o plano de construção, fase a fase,
  com o que deu errado em cada uma
- [`CLAUDE.md`](CLAUDE.md) — convenções para quem (ou o que) escreve código aqui
