<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an API token is allowed to do.
 *
 * Split by read and write rather than by resource: a token handed to a
 * reporting script should not be able to write anything, and that is the
 * distinction people actually reason about when creating one. Finer grained
 * abilities are easy to add later; taking them away once issued is not.
 *
 * A token's abilities are the ceiling, not the grant — whatever the token
 * allows, the person's role in the team still has to allow it too. A member
 * with a write token cannot do what a member cannot do.
 */
enum ApiAbility: string
{
    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Read => __('Read'),
            self::Write => __('Read and write'),
        };
    }

    /**
     * Abilities implied by holding this one.
     *
     * Write implies read: a token that can change a thing it cannot see would
     * be a strange thing to issue.
     *
     * @return array<int, string>
     */
    public function implies(): array
    {
        return match ($this) {
            self::Read => [self::Read->value],
            self::Write => [self::Read->value, self::Write->value],
        };
    }
}
