<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use Illuminate\Filesystem\FilesystemAdapter;
use Override;

/**
 * Extends the real adapter rather than reimplementing the interface: only the
 * read is a lie, and everything else should behave normally.
 */
class LosingAdapter extends FilesystemAdapter
{
    public function __construct(FilesystemAdapter $inner)
    {
        parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
    }

    #[Override]
    public function get($path): ?string
    {
        return null;
    }
}
