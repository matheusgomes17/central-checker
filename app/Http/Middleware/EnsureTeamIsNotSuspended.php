<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Billing\TeamBilling;
use App\Support\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a suspended team from changing anything.
 *
 * Reads still work: the account and its data stay visible, because the point
 * of read-only is that nothing is lost while the bill goes unpaid. What stops
 * is writing.
 *
 * Billing routes are always allowed through — a suspension that blocks the
 * page where you would pay is a suspension nobody can lift.
 */
class EnsureTeamIsNotSuspended
{
    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Routes that stay open regardless, by name prefix.
     *
     * @var array<int, string>
     */
    private const array ALWAYS_ALLOWED = ['billing.', 'cashier.', 'logout', 'impersonate.'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        $team = resolve(TenantManager::class)->current();

        if ($team !== null && TeamBilling::for($team)->isReadOnly()) {
            abort(Response::HTTP_PAYMENT_REQUIRED, __('This team is read-only until the subscription is renewed.'));
        }

        return $next($request);
    }

    private function isAlwaysAllowed(Request $request): bool
    {
        $name = $request->route()?->getName();

        if (! is_string($name)) {
            return false;
        }

        return array_any(self::ALWAYS_ALLOWED, fn (string $prefix): bool => str_starts_with($name, $prefix));
    }
}
