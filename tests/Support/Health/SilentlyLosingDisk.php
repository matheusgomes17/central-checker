<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * A disk whose writes report success and then are not there.
 *
 * The behavior of a full disk, and the reason the probe reads back instead of
 * trusting what put() returned.
 */
class SilentlyLosingDisk implements StorageFactory
{
    public function disk($name = null): Filesystem
    {
        return new LosingAdapter(Storage::fake('losing'));
    }
}
