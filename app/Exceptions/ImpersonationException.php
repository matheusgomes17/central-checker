<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ImpersonationException extends RuntimeException
{
    public static function notPermitted(): self
    {
        return new self(__('Only platform administrators can impersonate a user.'));
    }

    public static function self(): self
    {
        return new self(__('You are already signed in as yourself.'));
    }

    public static function alreadyActive(): self
    {
        return new self(__('Stop the current impersonation before starting another.'));
    }

    public static function targetIsPrivileged(): self
    {
        return new self(__('Platform administrators cannot be impersonated.'));
    }

    public static function notActive(): self
    {
        return new self(__('There is no impersonation to stop.'));
    }
}
