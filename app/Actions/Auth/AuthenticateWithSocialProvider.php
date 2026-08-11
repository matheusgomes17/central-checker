<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Teams\CreateTeam;
use App\Exceptions\SocialAuthenticationException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Resolves a social sign in to a local user, creating or linking as needed.
 *
 * This is the security-critical half of social login. Linking a provider
 * identity to an existing local account hands over that account, so the only
 * thing that may authorize it is proof that the person controls the account's
 * email address — which is exactly what a provider-verified email is.
 *
 * The rules, in order:
 *
 * 1. A known (provider, provider_id) pair signs its owner in. Nothing else is
 *    consulted, so a provider email change cannot move the link.
 * 2. An unverified or absent email is refused outright. Accepting it would let
 *    anyone who can set an address at a provider claim the matching local
 *    account, and would let them squat addresses they do not own.
 * 3. A verified email matching a local account links to it.
 * 4. Anything else creates a new account, already verified.
 */
class AuthenticateWithSocialProvider
{
    public function __construct(private readonly CreateTeam $createTeam) {}

    public function handle(string $provider, SocialiteUser $socialUser): User
    {
        $existingLink = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', (string) $socialUser->getId())
            ->first();

        if ($existingLink !== null) {
            return $existingLink->user;
        }

        $email = $socialUser->getEmail();

        if ($email === null || $email === '') {
            throw SocialAuthenticationException::missingEmail($provider);
        }

        if (! $this->providerVerifiedTheEmail($provider, $socialUser)) {
            throw SocialAuthenticationException::unverifiedEmail($provider);
        }

        // Uma conta excluída ainda ocupa o e-mail durante a retenção. Sem esta
        // checagem, o fluxo cairia na criação e estouraria no índice único —
        // ou pior, ressuscitaria a conta.
        if (User::onlyTrashed()->where('email', $email)->exists()) {
            throw SocialAuthenticationException::accountUnavailable();
        }

        return DB::transaction(function () use ($provider, $socialUser, $email): User {
            $user = User::query()->where('email', $email)->first()
                ?? $this->createUser($socialUser, $email);

            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => (string) $socialUser->getId(),
            ]);

            return $user;
        });
    }

    /**
     * Whether the provider asserts that it verified this email address.
     *
     * Google states it outright in the token payload. GitHub does not, but the
     * Socialite driver replaces the profile email with the primary verified
     * one whenever the "user:email" scope was granted — and it yields null
     * when there is none. Config requests that scope, so an email arriving
     * from GitHub at all is a verified one.
     */
    private function providerVerifiedTheEmail(string $provider, SocialiteUser $socialUser): bool
    {
        return match ($provider) {
            'google' => filter_var(
                data_get($socialUser, 'user.email_verified'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) === true,
            'github' => true,
            default => false,
        };
    }

    private function createUser(SocialiteUser $socialUser, string $email): User
    {
        $name = $socialUser->getName() ?? $socialUser->getNickname() ?? Str::before($email, '@');

        // Um insert só: email_verified_at não é fillable, e criar primeiro
        // para completar depois esbarra no NOT NULL da coluna de e-mail.
        $user = new User;

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            // Sem senha utilizável: quem entrou por OAuth nunca escolheu uma.
            // Um valor aleatório mantém a coluna não nula sem criar uma
            // credencial que alguém possa adivinhar; para ganhar senha, o
            // caminho é o fluxo de recuperação.
            'password' => Str::random(64),
            // O provedor acabou de provar controle do endereço, então exigir
            // uma segunda verificação por e-mail seria cerimônia sem ganho.
            'email_verified_at' => now(),
        ])->save();

        $this->createTeam->handle($user, $name."'s Team", isPersonal: true);

        return $user;
    }
}
