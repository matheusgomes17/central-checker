<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a notification is about, from the reader's point of view.
 *
 * Preferences are per topic and not per notification class: someone wants to
 * stop hearing about team activity, not about
 * TeamInvitationAcceptedNotification. Naming the classes in the interface
 * would also make every new notification a new setting nobody chose.
 */
enum NotificationTopic: string
{
    case Team = 'team';
    case Billing = 'billing';
    case Security = 'security';

    public function label(): string
    {
        return match ($this) {
            self::Team => __('Team activity'),
            self::Billing => __('Billing'),
            self::Security => __('Security'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Team => __('Invitations, members joining and leaving, role changes.'),
            self::Billing => __('Payment failures, plan changes and invoices.'),
            self::Security => __('Sign-ins from new devices and changes to your credentials.'),
        };
    }

    /**
     * Whether this topic can be silenced at all.
     *
     * Security cannot. Someone who turns off the warning that their password
     * changed has turned off the only thing that would tell them it was not
     * them who changed it.
     */
    public function isOptional(): bool
    {
        return $this !== self::Security;
    }
}
