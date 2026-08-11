<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;

/**
 * Lets a person choose how they hear from the application.
 *
 * Stored as opt-outs, so anything added later starts on. Two things cannot be
 * switched off, and both refusals are deliberate: the in-app record, because
 * an inbox missing what the account received is worse than a noisy one; and
 * the security topic, because turning off the warning that your password
 * changed turns off the only thing that would tell you it was not you.
 */
trait HasNotificationPreferences
{
    /**
     * Whether this person wants to hear about a topic on a channel.
     */
    public function wantsNotification(NotificationTopic $topic, NotificationChannel $channel): bool
    {
        if (! $channel->isOptional() || ! $topic->isOptional()) {
            return true;
        }

        return ! in_array($channel->value, $this->optOutsFor($topic), true);
    }

    /**
     * The channels to deliver a topic on for this person.
     *
     * @param  array<int, NotificationChannel>  $channels
     * @return array<int, string>
     */
    public function notificationChannelsFor(NotificationTopic $topic, array $channels): array
    {
        return array_values(array_map(
            fn (NotificationChannel $channel): string => $channel->value,
            array_filter($channels, fn (NotificationChannel $c): bool => $this->wantsNotification($topic, $c)),
        ));
    }

    /**
     * Record that a person does not want a topic on a channel.
     */
    public function setNotificationPreference(
        NotificationTopic $topic,
        NotificationChannel $channel,
        bool $wanted,
    ): void {
        if (! $channel->isOptional() || ! $topic->isOptional()) {
            return;
        }

        $optOuts = $this->allOptOuts();
        $forTopic = $optOuts[$topic->value] ?? [];

        $forTopic = $wanted
            ? array_values(array_diff($forTopic, [$channel->value]))
            : array_values(array_unique([...$forTopic, $channel->value]));

        $optOuts[$topic->value] = $forTopic;

        $this->forceFill(['notification_opt_outs' => $optOuts])->save();
    }

    /**
     * @return array<int, string>
     */
    private function optOutsFor(NotificationTopic $topic): array
    {
        return $this->allOptOuts()[$topic->value] ?? [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function allOptOuts(): array
    {
        $stored = $this->getAttribute('notification_opt_outs');

        if (! is_array($stored)) {
            return [];
        }

        $optOuts = [];

        foreach ($stored as $topic => $channels) {
            $names = [];

            foreach ((array) $channels as $channel) {
                if (is_string($channel)) {
                    $names[] = $channel;
                }
            }

            $optOuts[(string) $topic] = $names;
        }

        return $optOuts;
    }
}
