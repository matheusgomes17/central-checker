<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Team;

/**
 * Counts how much of one metered resource a team is using.
 *
 * The boilerplate ships one of these, for members. The product adds its own —
 * and the consistency test refuses a plan that names a limit before its
 * counter exists, which is the order that keeps a ceiling from being fiction.
 */
interface CountsPlanResource
{
    public function count(Team $team): int;
}
