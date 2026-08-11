<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;

/**
 * A database that is not there.
 */
class BrokenResolver implements ConnectionResolverInterface
{
    public const string REASON = 'too many connections';

    public function connection($name = null): ConnectionInterface
    {
        throw new RuntimeException(self::REASON);
    }

    public function getDefaultConnection(): string
    {
        return 'pgsql';
    }

    public function setDefaultConnection($name): void {}
}
