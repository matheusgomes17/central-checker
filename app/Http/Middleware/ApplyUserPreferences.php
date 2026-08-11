<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the signed in user's language to the request.
 *
 * Without this the language field is decoration: the user picks one, nothing
 * changes, and the setting reads as broken. A null preference means "use the
 * application default", so it is left untouched rather than overwritten.
 *
 * Timezone is deliberately not applied here. The application clock stays in
 * UTC — moving it per request would change how new timestamps are interpreted.
 * The preference is a display concern, resolved by User::effectiveTimezone()
 * wherever a date is rendered.
 */
class ApplyUserPreferences
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        /** @var array<string, string> $locales */
        $locales = config('accounts.locales', []);

        // Um locale que saiu da lista depois de escolhido não pode derrubar a
        // aplicação tentando carregar traduções que não existem mais.
        if ($user?->locale !== null && array_key_exists($user->locale, $locales)) {
            app()->setLocale($user->locale);

            // Sem isto, diffForHumans() continua respondendo em inglês.
            \Illuminate\Support\Facades\Date::setLocale($user->locale);
        }

        return $next($request);
    }
}
