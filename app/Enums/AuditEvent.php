<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The events worth keeping a permanent record of.
 *
 * Deliberately a short, closed list rather than logging every attribute change
 * on every model. An audit trail is only useful if someone reads it, and a log
 * that records everything records nothing anyone will look through. What earns
 * a place here is a change that moves power between people, or that gives one person
 * access to another person's account.
 */
enum AuditEvent: string
{
    case TeamRoleChanged = 'team.role_changed';
    case TeamOwnershipTransferred = 'team.ownership_transferred';
    case TeamMemberRemoved = 'team.member_removed';
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationStopped = 'impersonation.stopped';

    public function label(): string
    {
        return match ($this) {
            self::TeamRoleChanged => __('Role changed'),
            self::TeamOwnershipTransferred => __('Ownership transferred'),
            self::TeamMemberRemoved => __('Member removed'),
            self::ImpersonationStarted => __('Impersonation started'),
            self::ImpersonationStopped => __('Impersonation stopped'),
        };
    }
}
