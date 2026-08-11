<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateWithSocialProvider;
use App\Exceptions\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class SocialAuthController extends Controller
{
    public function redirect(string $provider): SymfonyRedirectResponse|RedirectResponse
    {
        $config = $this->configFor($provider);

        $driver = Socialite::driver($provider);

        // scopes() vive no AbstractProvider do OAuth 2, não no contrato. Os
        // provedores usados aqui são todos OAuth 2; a checagem existe para não
        // depender disso silenciosamente.
        if ($driver instanceof AbstractProvider) {
            $driver->scopes($config['scopes']);
        }

        return $driver->redirect();
    }

    public function callback(string $provider, AuthenticateWithSocialProvider $authenticate): RedirectResponse
    {
        $this->configFor($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            // Cai aqui quando a pessoa recusa a autorização ou o state expira.
            // Não há o que distinguir para o usuário: os dois significam "não
            // deu, tente de novo".
            return to_route('login')->withErrors([
                'email' => __('We could not complete the sign in. Please try again.'),
            ]);
        }

        try {
            $user = $authenticate->handle($provider, $socialUser);
        } catch (SocialAuthenticationException $e) {
            return to_route('login')->withErrors(['email' => $e->getMessage()]);
        }

        Auth::login($user, remember: true);

        // Sessão nova a cada login: sem isto, um id de sessão fixado antes do
        // login continua válido depois dele.
        request()->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * The configuration for an enabled provider, or a 404.
     *
     * The provider arrives as a URL segment, so the allow list is what stops it
     * from being a way to probe which Socialite drivers exist.
     *
     * @return array{label: string, scopes: array<int, string>}
     */
    private function configFor(string $provider): array
    {
        /** @var array<string, array{label: string, scopes: array<int, string>}> $providers */
        $providers = config('social.providers', []);

        abort_unless(array_key_exists($provider, $providers), 404);
        abort_unless(is_string(config("services.{$provider}.client_id")), 404);

        return $providers[$provider];
    }
}
