<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles that belong to the platform rather than to a team.
 *
 * Kept apart from TeamRole on purpose. A team role answers "what may this
 * person do inside this team"; a system role answers "what may this person do
 * to the platform". Folding the two together would make it possible to grant
 * super admin scoped to a single team, which reads like a safety limit and is
 * not one.
 */
enum SystemRole: string
{
    case SuperAdmin = 'super-admin';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
        };
    }
}
