<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\WebhookEvent;
use App\Models\Membership;
use App\Support\Webhooks\WebhookDispatcher;

/**
 * Turns a membership row changing into an outgoing event.
 *
 * On the model rather than at each call site because there are already four
 * places a membership is created — team creation, the invite page, the import
 * job, and the API — and a fifth will not remember to emit anything.
 *
 * The catch is that model events only fire when a model is deleted, not when a
 * query deletes rows. `$team->memberships()->where(...)->delete()` is silent.
 * The removal paths therefore delete the model, and a test holds them to it.
 */
class MembershipObserver
{
    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    public function created(Membership $membership): void
    {
        $this->webhooks->send(WebhookEvent::MemberJoined, $membership->team, [
            'team_id' => $membership->team_id,
            'user_id' => $membership->user_id,
        ]);
    }

    public function deleted(Membership $membership): void
    {
        $this->webhooks->send(WebhookEvent::MemberRemoved, $membership->team, [
            'team_id' => $membership->team_id,
            'user_id' => $membership->user_id,
        ]);
    }
}
