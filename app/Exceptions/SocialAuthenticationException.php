<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a social sign in cannot be completed safely.
 *
 * The message is written to be shown to the person trying to sign in, so it
 * says what to do next without describing whether an account exists — that
 * would turn the login screen into an account enumeration oracle.
 */
class SocialAuthenticationException extends RuntimeException
{
    public static function missingEmail(string $provider): self
    {
        return new self(__(
            'We could not read a verified email address from your :provider account. Sign in with your email and password instead.',
            ['provider' => $provider],
        ));
    }

    public static function unverifiedEmail(string $provider): self
    {
        return new self(__(
            'Your :provider email address is not verified. Verify it there and try again, or sign in with your email and password.',
            ['provider' => $provider],
        ));
    }

    public static function accountUnavailable(): self
    {
        return new self(__(
            'This account is not available. Contact support if you believe this is a mistake.',
        ));
    }
}
