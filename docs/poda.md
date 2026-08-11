# Como remover o que você não vai usar

Boilerplate bom é fácil de podar. Este documento diz como tirar cada peça
grande sem deixar resto — rota que aponta para controller que não existe,
migration criando tabela que ninguém lê, teste verde porque não testa mais nada.

**A regra de sempre:** depois de cada poda, `composer check`. A suíte é o que
distingue "removi" de "removi metade". Se um teste some junto com a peça, ele
some de propósito e o commit tem que dizer isso — [deletar teste sem dizer por
quê](../CLAUDE.md) é como o boilerplate perde cobertura sem ninguém notar.

Comece por uma migration limpa. Podar com dados dentro é possível, e aí cada
item ganha uma migration de remoção — a ordem muda e o risco também.

---

## Cobrança (assinatura pré-paga via Pix)

Tire se o produto for gratuito, cobrar fora do aplicativo, ou usar outro
provedor. Não há mais Cashier/Stripe neste boilerplate — a cobrança é
`PlanSubscription` local, resolvida pelo webhook da VirtualPay (veja a poda de
Webhooks acima para o gateway em si).

1. **Código:** `app/Support/Billing/`, `app/Models/PlanSubscription.php`,
   `app/Http/Middleware/EnsureWithinPlanLimits.php`,
   `app/Filament/Resources/Subscriptions/`,
   `app/Filament/Widgets/RevenueOverview.php`,
   `resources/views/pages/settings/⚡billing.blade.php`
2. **Model `Team`:** tire `trial_ends_at` e o que depender dele.
3. **Provider:** tire `configureBilling()` do `AppServiceProvider`.
4. **Rotas:** a rota `settings/billing` em `routes/settings.php`.
5. **Alias:** `plan-feature` em `bootstrap/app.php`.
6. **Config e migrations:** `config/billing.php`, a migration de
   `plan_subscriptions` e a coluna `trial_ends_at` de `teams`.
7. **Papel:** `TeamRole::Billing` e as permissões `ViewBilling` e
   `ManageBilling` nos enums — e rode o seeder de papéis, que sincroniza.
8. **Testes:** `tests/Feature/Billing/`, `tests/Feature/Admin/BillingPanelTest.php`.

Cuidado: o `DemoSeeder` cria assinaturas. Tire o método `subscriptions()` dele
junto, ou o seeder quebra na primeira execução.

---

## API pública (Sanctum + Scramble)

Tire se ninguém vai integrar por fora, ou se a integração for só por webhook.

1. **Código:** `app/Http/Controllers/Api/`, `app/Http/Resources/Api/`,
   `app/Http/Middleware/SetTenantFromToken.php`,
   `app/Http/Middleware/EnsureTokenAbility.php`, `app/Models/ApiToken.php`,
   `app/Enums/ApiAbility.php`, `app/Support/Api/`,
   `resources/views/pages/settings/⚡api-tokens.blade.php`
2. **Rotas:** `routes/api.php` e a linha `api:` em `bootstrap/app.php`.
3. **Middleware:** `$middleware->api(append: [SetTenantFromToken::class])` e o
   alias `ability`.
4. **Provider:** `configureApi()` no `AppServiceProvider`.
5. **Config e migrations:** `config/sanctum.php`, `config/scramble.php`, a
   migration de `personal_access_tokens`.
6. **Testes:** `tests/Feature/Api/`.
7. **Pacotes:** `composer remove laravel/sanctum dedoc/scramble`.

O formato de erro Problem Details em `bootstrap/app.php` só serve à API; ele sai
junto.

---

## Tempo real (Reverb + Echo)

Tire se nenhuma tela precisa mudar sozinha. As notificações continuam
funcionando por banco e e-mail.

1. **Código:** `routes/channels.php`, `resources/js/echo.js` e o import dele em
   `resources/js/app.js`.
2. **Enum:** o caso `Broadcast` em `NotificationChannel` e o que o lê nas
   preferências de notificação.
3. **Config:** `config/reverb.php`; em `config/broadcasting.php`, deixe o driver
   `null`.
4. **Testes:** `tests/Feature/Broadcasting/`.
5. **Pacotes:** `composer remove laravel/reverb` e `npm remove laravel-echo pusher-js`.
6. **Ambiente:** `REVERB_*` e `VITE_REVERB_*` no `.env.example`.
7. **Composer:** tire o processo do Reverb do script `dev`.

---

## Webhooks de saída

Tire se você não vai entregar eventos para sistemas de clientes.

1. **Código:** `app/Support/Webhooks/`, `app/Jobs/DeliverWebhook.php`,
   `app/Models/WebhookEndpoint.php`, `app/Enums/WebhookEvent.php`,
   `resources/views/pages/settings/⚡webhooks.blade.php`
2. **Observers:** `MembershipObserver` e `TeamInvitationObserver` disparam
   eventos; tire só as chamadas ao dispatcher, não os observers inteiros.
3. **Permissão:** `TeamPermission::ManageWebhooks` nos papéis.
4. **Migrations:** `webhook_endpoints` e `webhook_deliveries`.
5. **Testes:** `tests/Feature/Webhooks/`.

O `WebhookSignature` é usado também na verificação do webhook da VirtualPay. Se
você manteve a cobrança, ele fica.

---

## Painel do operador (Filament)

Tire se um `tinker` já resolve o suporte que você faz.

1. **Código:** `app/Filament/` e `app/Providers/Filament/`.
2. **Model `User`:** o contrato `FilamentUser` e o `canAccessPanel()`.
3. **Testes:** `tests/Feature/Admin/` e `tests/Feature/FilamentCoexistenceTest.php`.
4. **Pacote:** `composer remove filament/filament`.
5. **Composer:** `filament:assets` no script `setup`, e `filament:upgrade` no
   `post-autoload-dump`.
6. **Gitignore:** as linhas de `public/*/filament`.

A impersonation não é do Filament — ela tem rota própria e continua. O que sai é
o botão que a inicia.

---

## Observabilidade (Pulse, Telescope, Sentry)

Pode sair separadamente. O rastro por requisição — `X-Request-Id` e `team_id` no
contexto — não depende de nenhum dos três e vale manter.

- **Pulse:** `config/pulse.php`, o card em `app/Providers/AppServiceProvider.php`,
  as migrations do Pulse, `composer remove laravel/pulse`.
- **Telescope:** o registro condicional no `register()` do `AppServiceProvider`,
  `app/Providers/TelescopeServiceProvider.php`, `config/telescope.php`,
  `composer remove laravel/telescope`.
- **Sentry:** `configureObservability()`, `app/Support/Observability/SentryContext.php`,
  `config/sentry.php`, a linha `Integration::handles()` em `bootstrap/app.php`,
  `composer remove sentry/sentry-laravel`.

---

## Busca (Meilisearch)

Nunca foi integrado — o contêiner sobe e ninguém indexa nada. Para tirar: o
serviço `meilisearch` do `compose.yaml`, o volume dele, e `MEILISEARCH_*` no
`.env.example` e na constante de variáveis do `AppServiceProvider`.

---

## Login social e passkeys

- **Social:** `app/Actions/Auth/AuthenticateWithSocialProvider.php`,
  `app/Http/Controllers/Auth/SocialAuthController.php`, `app/Models/SocialAccount.php`,
  `config/social.php`, as rotas `auth/{provider}/*`, a migration de
  `social_accounts`, o componente `x-social-auth-buttons`,
  `composer remove laravel/socialite`.
- **Passkeys e 2FA:** são recursos do Fortify. Desligue em `config/fortify.php`
  antes de sair apagando view — o starter kit registra as rotas a partir dali.

---

## O que não sai

Times, papéis e o escopo por tenant. Não são peças plugadas: são a forma do
banco e a premissa de toda policy. Um produto de um único tenant não deveria
partir deste boilerplate — o custo de arrancar a tenancy é maior que o de
começar de um `laravel new`.
