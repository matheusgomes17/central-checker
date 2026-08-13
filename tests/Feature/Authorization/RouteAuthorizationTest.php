<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * The phase acceptance criterion: no authenticated route without a policy or a
 * written reason for not having one.
 *
 * There is no way to detect "this route authorizes properly" by inspection —
 * authorization happens inside controllers, Livewire components and middleware,
 * in shapes no reflection will recognize. So the test does the honest version
 * of the rule: every authenticated route has to be listed here with a reason,
 * and a new one that nobody classified fails the build.
 *
 * That turns the criterion into something a reviewer answers once per route,
 * at the moment the route is added, instead of a promise nobody re-checks.
 */
class RouteAuthorizationTest extends TestCase
{
    /**
     * What authorizes each authenticated route.
     *
     * Routes guarded by a policy or by the platform role say which one, so
     * this list doubles as the map of what is protected by what. The rest say
     * why no resource policy applies.
     *
     * Guest-only routes are absent on purpose: they are not authenticated, and
     * counting them here would pad the list with rows nobody needs to review.
     *
     * @var array<string, string>
     */
    /**
     * Route families guarded as a whole, by name prefix.
     *
     * A package that registers thirty routes behind one gate does not need
     * thirty justifications — the gate is the justification, and it holds for
     * whatever the package adds next. Listing them one by one would turn this
     * file into noise, and a list nobody reads stops being a review.
     *
     * @var array<string, string>
     */
    private const array ACCOUNTED_PREFIXES = [
        // Toda rota da API exige token; o token nomeia o time, e o middleware
        // define o tenant a partir dele antes de qualquer consulta. A
        // habilidade do token e a policy do papel são checadas por rota.
        'api.' => 'auth:sanctum + tenant vindo do token + ability por rota',
        'filament.admin.' => 'canAccessPanel exige super-admin',
        'horizon.' => 'gate viewHorizon exige super-admin',
    ];

    private const array ACCOUNTED_FOR = [
        // Guardadas por TeamPolicy.
        'teams.index' => 'TeamPolicy::viewAny',
        'teams.edit' => 'TeamPolicy::view e update, autorizadas na própria página',
        'invitations.accept' => 'valida o convite pelo código e pelo e-mail de quem aceita',

        // Cobrança: o recurso é o time atual, e a permissão é verificada contra
        // ele antes de qualquer coisa.
        'billing.edit' => 'exige ViewBilling no time atual',

        // Guardadas pelo papel de plataforma.
        'impersonate.start' => 'exige super-admin na action de impersonation',

        // Agem sobre quem está autenticado: o recurso é a própria pessoa, e não
        // existe identificador de outra para autorizar contra.
        'dashboard' => 'o escopo de time vem do middleware de membership',
        'checker.execute' => 'o escopo de time vem do middleware de membership',
        'checker.wallet' => 'o escopo de time vem do middleware de membership; recharge() exige ManageBilling',
        'checker.plans' => 'o escopo de time vem do middleware de membership; subscribe() exige ManageBilling',
        'checker.history' => 'o escopo de time vem do middleware de membership; executions() e selected() filtram por user_id',
        'profile.edit' => 'edita a própria conta',
        'security.edit' => 'edita a própria segurança',
        'appearance.edit' => 'preferência da própria conta',
        'notifications.edit' => 'preferências de notificação da própria conta',
        'api-tokens.edit' => 'tokens da própria conta, escopados ao time atual',
        'webhooks.edit' => 'endpoints do time atual; a policy manageWebhooks decide quem escreve',
        'impersonate.stop' => 'sai da própria sessão de impersonation, de propósito sem exigir privilégio',
        'logout' => 'encerra a própria sessão',

        // Fluxos do Fortify sobre a própria conta, todos atrás de confirmação
        // de senha onde ela é exigida.
        'password.confirm' => 'confirma a própria senha',
        'password.confirm.store' => 'confirma a própria senha',
        'password.confirmation' => 'estado da confirmação de senha',
        'verification.notice' => 'aviso de verificação do próprio e-mail',
        'verification.send' => 'reenvia verificação do próprio e-mail',
        'verification.verify' => 'verifica o próprio e-mail por URL assinada',
        'two-factor.enable' => 'ativa 2FA na própria conta',
        'two-factor.confirm' => 'confirma 2FA na própria conta',
        'two-factor.disable' => 'desativa 2FA na própria conta',
        'two-factor.qr-code' => 'segredo de 2FA da própria conta',
        'two-factor.secret-key' => 'segredo de 2FA da própria conta',
        'two-factor.recovery-codes' => 'códigos de recuperação da própria conta',
        'two-factor.regenerate-recovery-codes' => 'códigos de recuperação da própria conta',
        'passkey.confirm' => 'confirma identidade por passkey',
        'passkey.confirm-options' => 'opções de confirmação por passkey',
        'passkey.store' => 'registra passkey na própria conta',
        'passkey.registration-options' => 'opções de registro de passkey',
        'passkey.destroy' => 'remove passkey da própria conta, buscada pela relação do usuário',
    ];

    public function test_every_authenticated_route_is_accounted_for(): void
    {
        $unaccounted = array_values(array_filter(
            $this->authenticatedRouteNames(),
            fn (string $name): bool => ! array_key_exists($name, self::ACCOUNTED_FOR)
                && ! $this->matchesAccountedPrefix($name),
        ));

        $this->assertSame([], $unaccounted, sprintf(
            "Rota autenticada sem justificativa registrada: %s.\n".
            'Adicione a rota em %s::ACCOUNTED_FOR dizendo o que a autoriza.',
            implode(', ', $unaccounted),
            self::class,
        ));
    }

    /**
     * The list has to shrink when a route goes away, otherwise it turns into a
     * graveyard of reasons for routes nobody can find any more.
     */
    public function test_the_list_has_no_entries_for_routes_that_no_longer_exist(): void
    {
        $stale = array_values(array_diff(array_keys(self::ACCOUNTED_FOR), $this->authenticatedRouteNames()));

        $this->assertSame([], $stale, 'Entradas para rotas que não existem mais: '.implode(', ', $stale).'.');
    }

    private function matchesAccountedPrefix(string $name): bool
    {
        return array_any(array_keys(self::ACCOUNTED_PREFIXES), fn (string $prefix): bool => str_starts_with($name, $prefix));
    }

    /**
     * @return array<int, string>
     */
    private function authenticatedRouteNames(): array
    {
        $names = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if ($this->routeRequiresAuthentication($route) && is_string($route->getName())) {
                $names[] = $route->getName();
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Middleware arrives here as declared, not as resolved.
     *
     * `route:list` prints the resolved class names, but gatherMiddleware()
     * returns whatever the route declared — usually the `auth` alias. Matching
     * only the class name found almost nothing, which is a comfortable way for
     * this test to pass while checking nothing.
     */
    private function routeRequiresAuthentication(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth' || str_starts_with($middleware, 'auth:')) {
                return true;
            }

            if (str_contains($middleware, 'Middleware\\Authenticate')) {
                return true;
            }
        }

        return false;
    }
}
