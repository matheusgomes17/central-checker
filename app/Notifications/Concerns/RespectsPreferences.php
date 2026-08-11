<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\User;

/**
 * Routes a notification through the channels its reader agreed to.
 *
 * The topic is declared by the notification; the channels are whatever remains
 * after the reader's preferences. A notifiable that is not a User — an
 * on-demand address, for instance — has no preferences to consult and gets
 * everything the notification offers.
 */
trait RespectsPreferences
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = $this->channels();

        if (! $notifiable instanceof User) {
            return array_map(fn (NotificationChannel $c): string => $c->value, $channels);
        }

        return $notifiable->notificationChannelsFor($this->topic(), $channels);
    }

    /**
     * What this notification is about.
     */
    abstract public function topic(): NotificationTopic;

    /**
     * Where it could be delivered, before preferences are applied.
     *
     * @return array<int, NotificationChannel>
     */
    abstract public function channels(): array;
}
