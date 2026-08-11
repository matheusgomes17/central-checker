<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

class BrokenStorage implements StorageFactory
{
    public function disk($name = null): Filesystem
    {
        throw new RuntimeException('disco indisponível');
    }
}
