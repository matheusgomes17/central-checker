<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request the token was not issued to make.
 *
 * Separate from the policy check on purpose, and both run. The token's ability
 * is a ceiling on what the credential may attempt; the person's role is what
 * they are allowed to do at all. A write token held by someone who is only a
 * member still cannot remove anyone.
 */
class EnsureTokenAbility
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            return $next($request);
        }

        $required = ApiAbility::tryFrom($ability);

        abort_if($required === null, Response::HTTP_INTERNAL_SERVER_ERROR, 'Habilidade desconhecida.');

        abort_unless(
            $token->can($required->value),
            Response::HTTP_FORBIDDEN,
            __('This token cannot :ability.', ['ability' => $required->value]),
        );

        return $next($request);
    }
}
