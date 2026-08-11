<?php

declare(strict_types=1);

namespace App\Providers;

use App\Features\PlanFeature;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\Counters\MemberCounter;
use App\Support\Billing\ResourceCounters;
use App\Support\Impersonation;
use App\Support\Observability\SentryContext;
use App\Support\TenantManager;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\HttpSecurityScheme;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Value;
use Laravel\Sanctum\Sanctum;
use Override;
use Sentry\Laravel\Facade as Sentry;
use Sentry\State\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Variables the Compose file overrides for the application container.
     *
     * Duplicated from compose.yaml on purpose: an architecture test compares
     * the two, so an override added there without being added here fails
     * loudly instead of silently reaching only half the container.
     *
     * @var list<string>
     */
    public const array CONTAINER_SERVICE_VARIABLES = [
        'DB_HOST',
        'REDIS_HOST',
        'MAIL_HOST',
        'MEILISEARCH_HOST',
        'AWS_ENDPOINT',
    ];

    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        // Registrado à mão e só em local. O Telescope é dependência de
        // desenvolvimento: em produção o `composer install --no-dev` não traz
        // a classe, e listá-lo em bootstrap/providers.php derrubaria o boot.
        // Ele também grava tudo — corpo de requisição, consulta, e-mail — que
        // é útil na máquina de quem desenvolve e é vazamento em qualquer
        // outro lugar.
        if ($this->app->environment('local') && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->singleton(TenantManager::class);

        // O contador de membros é a implementação de referência. O produto
        // registra os dele aqui, e só então pode limitá-los na config — o teste
        // de consistência falha na ordem inversa, e é essa a intenção.
        $this->app->singleton(ResourceCounters::class, function (): ResourceCounters {
            $counters = new ResourceCounters;
            $counters->register('members', new MemberCounter);

            return $counters;
        });

        // As rotas do Cashier são registradas por ele mesmo. Ignorá-las aqui é
        // o que permite registrar as nossas, com o controller estendido, sem
        // ficar com duas rotas para o mesmo caminho.
        Cashier::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->passServiceHostsThroughToTheDevServer();
        $this->configureBilling();
        $this->configureObservability();
        $this->configureApi();
        $this->forgetTenantWhenTheUserChanges();
        $this->grantEverythingToSuperAdmins();
    }

    /**
     * Drop the resolved tenant whenever the authenticated user changes.
     *
     * The manager caches its answer for the rest of the request. Logging in or
     * out mid-request would otherwise leave the previous user's team in place,
     * which on a shared singleton is a tenant leak rather than a stale value.
     */
    protected function forgetTenantWhenTheUserChanges(): void
    {
        Event::listen([Login::class, Logout::class, Authenticated::class], function (): void {
            $this->app->make(TenantManager::class)->forget();
        });
    }

    /**
     * Point Cashier at the team.
     *
     * Cashier assumes the User model unless told otherwise, and there is no
     * environment variable for this — it has to be said in code, before
     * anything resolves a billable.
     */
    /**
     * Point Sanctum at the token model that knows about teams.
     */
    protected function configureApi(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        // Quem lê a documentação também precisa saber como autenticar. Sem
        // isto o OpenAPI descreve as rotas e omite que todas exigem um token.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $document): void {
            // O construtor concreto em vez do atalho estático: o helper do
            // pacote não declara o tipo de retorno, e o analisador não tem
            // como saber que recebe um SecurityScheme.
            $document->secure(new HttpSecurityScheme('bearer'));
        });

        // A documentação de uma API interna não é pública. O padrão do pacote
        // é liberar só em local; aqui a regra é a mesma do painel do operador,
        // para não haver duas respostas para "quem é administrador".
        Gate::define('viewApiDocs', fn (?User $user): bool => $user?->isSuperAdmin() ?? false);
    }

    /**
     * Configures what the metrics dashboard shows and who may see it.
     */
    protected function configureObservability(): void
    {
        // Mesma regra do Horizon e da documentação: uma resposta só para
        // "quem é administrador". O padrão do Pulse libera em local e fecha no
        // resto, o que deixaria o painel inacessível em produção.
        Gate::define('viewPulse', fn (?User $user): bool => $user?->isSuperAdmin() ?? false);

        // O painel mostra por usuário, e por usuário não responde a pergunta
        // que se faz num SaaS multi-inquilino: qual cliente está lento, qual
        // cliente está gastando a fila. O time entra junto do nome.
        Pulse::user(fn (User $user): array => [
            'name' => $user->name,
            'extra' => $user->currentTeam instanceof Team ? $user->currentTeam->name : '',
            'avatar' => null,
        ]);

        // Quem está sendo personificado não é quem está usando. Registrar o
        // alvo faria o painel atribuir ao cliente o tráfego gerado pelo
        // suporte olhando a conta dele.
        Pulse::filter(fn (Entry|Value $entry): bool => ! resolve(Impersonation::class)->isActive());

        // Um alerta que só diz o que quebrou manda quem está de plantão
        // procurar no log para quem quebrou. O critério da fase é o contexto
        // de tenant vir no próprio alerta.
        Sentry::configureScope(fn (Scope $scope) => resolve(SentryContext::class)->applyTo($scope));
    }

    protected function configureBilling(): void
    {
        Cashier::useCustomerModel(Team::class);

        PlanFeature::registerAll();
    }

    /**
     * Let platform administrators through every authorization check.
     *
     * Returns null rather than false for everyone else: false here would be a
     * verdict, short-circuiting the policies that should be answering. Only a
     * grant short-circuits.
     */
    protected function grantEverythingToSuperAdmins(): void
    {
        Gate::before(fn (User $user): ?bool => $user->isSuperAdmin() ? true : null);
    }

    /**
     * Let the dev server see the service hosts the container overrides.
     *
     * `artisan serve` does not hand its whole environment to the PHP process
     * that answers requests — it copies only the variables in this list, and
     * everything else the server re-reads from the .env file. That .env points
     * at 127.0.0.1, which is right on the host and wrong inside the container,
     * where the Compose file overrides the hosts by name.
     *
     * The result was a container whose CLI reached Postgres and whose HTTP did
     * not, which reads as a networking problem and is not one. Naming the
     * variables here is what makes the two agree.
     *
     * Harmless outside the container: nothing sets these there, so nothing is
     * copied and the server falls back to the .env exactly as before.
     */
    protected function passServiceHostsThroughToTheDevServer(): void
    {
        ServeCommand::$passthroughVariables = [
            ...ServeCommand::$passthroughVariables,
            ...self::CONTAINER_SERVICE_VARIABLES,
        ];
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
