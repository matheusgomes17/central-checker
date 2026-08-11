<?php

declare(strict_types=1);

use App\Http\Middleware\AddRequestContext;
use App\Http\Middleware\ApplyUserPreferences;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsureTeamIsNotSuspended;
use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\SetTenantFromToken;
use App\Support\Api\ApiProblem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Versão no prefixo desde o começo: acrescentar /v2 depois é barato,
        // mover uma API que nasceu sem versão não é.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Em todas as pilhas e no topo: uma requisição sem identificador é uma
        // requisição que não dá para seguir no log depois.
        $middleware->prepend(AddRequestContext::class);

        $middleware->web(append: [
            SetTeamUrlDefaults::class,
            ApplyUserPreferences::class,
        ]);

        $middleware->alias([
            'not-suspended' => EnsureTeamIsNotSuspended::class,
            'ability' => EnsureTokenAbility::class,
            'plan-feature' => EnsurePlanFeature::class,
        ]);

        // O tenant da API vem do token, e precisa estar definido antes de
        // qualquer coisa consultar um model escopado.
        $middleware->api(append: [SetTenantFromToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sem DSN o Sentry é inerte, então isto pode ficar registrado sempre:
        // um boilerplate que exige conta de terceiro para rodar em local não
        // roda em local.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Formato de erro único para toda a API, no formato Problem Details
        // (RFC 7807). Sem isto, quem consome precisa escrever um parser por
        // tipo de falha: validação sai de um jeito, 404 de outro, e o 500 de
        // um terceiro.
        $exceptions->render(function (Throwable $e, Request $request): ?Illuminate\Http\JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiProblem::from($e)->toResponse();
        });
    })->create();
