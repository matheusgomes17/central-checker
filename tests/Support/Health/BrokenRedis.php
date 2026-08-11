<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

class BrokenRedis implements RedisFactory
{
    public function connection($name = null): mixed
    {
        throw new RuntimeException('sem conexão com o redis');
    }
}
