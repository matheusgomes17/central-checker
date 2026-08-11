<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InvitationResendException extends RuntimeException
{
    public static function alreadyAccepted(): self
    {
        return new self(__('This invitation has already been accepted.'));
    }

    public static function tooSoon(int $minutes): self
    {
        return new self(__('Wait :minutes minutes before sending this invitation again.', [
            'minutes' => $minutes,
        ]));
    }
}
