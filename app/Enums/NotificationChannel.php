<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The ways the application can reach someone.
 *
 * Database is not listed as optional anywhere in the interface: it is the
 * record of what happened, and letting someone switch it off would mean the
 * in-app inbox silently missing events the account did receive.
 */
enum NotificationChannel: string
{
    case Mail = 'mail';
    case Database = 'database';
    case Broadcast = 'broadcast';

    public function label(): string
    {
        return match ($this) {
            self::Mail => __('Email'),
            self::Database => __('In app'),
            self::Broadcast => __('Real time'),
        };
    }

    /**
     * Whether the user may turn this channel off.
     */
    public function isOptional(): bool
    {
        return $this !== self::Database;
    }

    /**
     * @return array<int, self>
     */
    public static function optional(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c): bool => $c->isOptional()));
    }
}
