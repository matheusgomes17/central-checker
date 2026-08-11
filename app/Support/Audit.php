<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Records the handful of events the application keeps a permanent trail of.
 *
 * A thin front for the activity log, existing for two reasons. It fixes the
 * log name so audit entries never mix with whatever else the application
 * decides to log, and it takes an enum instead of a string so an event name
 * cannot be quietly misspelled into a record nobody will ever find again.
 */
class Audit
{
    public const string LOG = 'audit';

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        AuditEvent $event,
        Model $subject,
        array $properties = [],
        ?Model $causer = null,
    ): ?Activity {
        $activity = activity(self::LOG)
            ->performedOn($subject)
            ->event($event->value)
            ->withProperties($properties);

        // Causer explícito quando quem age não é quem está autenticado — um
        // job, um comando, ou uma ação feita durante impersonation.
        if ($causer instanceof Model) {
            $activity->causedBy($causer);
        }

        return $activity->log($event->value);
    }
}
