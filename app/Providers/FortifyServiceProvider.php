<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Support\Billing\TeamBilling;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Override;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Rotas do Fortify que recebem um limiter da aplicação, e qual.
     *
     * @var array<string, string>
     */
    private const array THROTTLED_ROUTES = [
        'password.email' => 'password-reset',
        'password.update' => 'password-reset',
        'register.store' => 'register',
    ];

    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();

        // As rotas do Fortify só existem depois que o provider dele boota.
        $this->app->booted($this->throttleFortifyRoutes(...));
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request): ViewFactory|View => view('pages::auth.login', [
            'teamInvitation' => $this->teamInvitation($request),
        ]));
        Fortify::verifyEmailView(fn (): ViewFactory|View => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn (): ViewFactory|View => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn (): ViewFactory|View => view('pages::auth.confirm-password'));
        Fortify::registerView(fn (Request $request): ViewFactory|View => view('pages::auth.register', [
            'teamInvitation' => $this->teamInvitation($request),
        ]));
        Fortify::resetPasswordView(fn (): ViewFactory|View => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn (): ViewFactory|View => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        RateLimiter::for('login', function (Request $request) {
            $username = $request->string(Fortify::username())->toString();

            $throttleKey = Str::transliterate(Str::lower($username).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // O password broker já limita pedidos repetidos para o mesmo e-mail,
        // mas esse limite é por endereço: sozinho, ele deixa uma única origem
        // percorrer uma lista e disparar e-mail para todos. Este limita a
        // origem.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));

        // Registro não tem limite nenhum no Fortify: uma origem só pode criar
        // contas na velocidade em que consegue postar, e cada uma custa uma
        // linha e um e-mail de verificação.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));

        // O limite da API acompanha o plano do time do token. Um número só
        // para todo mundo obriga a escolher entre estrangular quem paga e
        // deixar o gratuito custar caro.
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $team = $token instanceof ApiToken ? $token->team : null;

            if (! $team instanceof Team) {
                return Limit::perMinute(30)->by((string) $request->ip());
            }

            return Limit::perMinute(TeamBilling::for($team)->plan()->apiRateLimit)
                ->by('team:'.$team->id);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->string('credential.id')->toString();

            return Limit::perMinute(10)->by(
                ($credentialId !== '' ? $credentialId : $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }

    /**
     * Attach the application's limiters to the routes Fortify registers.
     *
     * Fortify only exposes limiter configuration for login, two factor and
     * passkeys, so these have to be decorated after the fact.
     *
     * The check reads the route's own middleware list rather than
     * gatherMiddleware(): the latter caches into computedMiddleware, and
     * priming that cache before mutating the route would leave the throttle
     * out of what the router actually dispatches. Skipping routes that
     * already carry it keeps this safe under `route:cache`, where the cached
     * routes come back with the middleware already attached.
     */
    private function throttleFortifyRoutes(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $limiter = self::THROTTLED_ROUTES[$route->getName()] ?? null;

            if ($limiter === null) {
                continue;
            }

            $middleware = 'throttle:'.$limiter;

            if (in_array($middleware, $route->middleware(), true)) {
                continue;
            }

            $route->middleware($middleware);
        }
    }

    /**
     * Get the pending team invitation context for auth pages.
     *
     * @return array{code: string, teamName: string}|null
     */
    private function teamInvitation(Request $request): ?array
    {
        $invitationCode = $request->query('invitation');

        if (! is_string($invitationCode)) {
            return null;
        }

        $invitation = TeamInvitation::query()
            ->with('team')
            ->where('code', $invitationCode)
            ->whereNull('accepted_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();

        if (! $invitation) {
            return null;
        }

        return [
            'code' => $invitation->code,
            'teamName' => $invitation->team->name,
        ];
    }
}
