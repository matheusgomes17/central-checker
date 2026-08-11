<?php

declare(strict_types=1);

namespace App\Support\Billing\Counters;

use App\Models\Team;
use App\Support\Billing\CountsPlanResource;

/**
 * Members plus the invitations still waiting to be accepted.
 *
 * Counting only memberships makes the ceiling trivially avoidable: a team two
 * seats from its limit sends fifty invitations and ends up fifty seats over it
 * when they are accepted. An expired invitation frees its seat again.
 */
class MemberCounter implements CountsPlanResource
{
    public function count(Team $team): int
    {
        $pending = $team->invitations()->pending()->count();

        return $team->memberships()->count() + $pending;
    }
}
