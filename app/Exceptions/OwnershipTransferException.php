<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a team cannot change hands.
 *
 * Each case is a separate constructor so the reason survives to the interface
 * instead of collapsing into one generic refusal.
 */
class OwnershipTransferException extends RuntimeException
{
    public static function notTheOwner(): self
    {
        return new self(__('Only the current owner can transfer a team.'));
    }

    public static function sameUser(): self
    {
        return new self(__('You already own this team.'));
    }

    public static function notAMember(): self
    {
        return new self(__('You can only transfer a team to one of its members.'));
    }

    public static function personalTeam(): self
    {
        return new self(__('A personal team cannot be transferred.'));
    }
}
