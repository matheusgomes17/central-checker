<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Who may open the Horizon dashboard.
     *
     * Not a convenience question. The dashboard shows job payloads, and job
     * payloads carry whatever the job was given — team ids, email addresses,
     * anything a queued notification is about. So it is the same door as the
     * operator panel: platform administrators only.
     *
     * The generated stub takes a list of e-mail addresses. Such a list
     * drifts from who actually works here; the role does not.
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user?->isSuperAdmin() ?? false);
    }
}
