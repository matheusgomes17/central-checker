<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use RuntimeException;

/**
 * The connection is up but the queue behind it cannot be read.
 */
class BrokenQueue implements QueueFactory
{
    public function connection($name = null): Queue
    {
        throw new RuntimeException('fila indisponível');
    }
}
