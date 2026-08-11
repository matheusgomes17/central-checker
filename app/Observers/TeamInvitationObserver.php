<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\WebhookEvent;
use App\Models\TeamInvitation;
use App\Support\Webhooks\WebhookDispatcher;

/**
 * Announces an invitation without announcing its code.
 *
 * The token in an invitation is the credential that gets someone into the
 * team. Putting it in an outgoing payload would mail a key to whatever server
 * the customer configured, and to every service that server logs to.
 */
class TeamInvitationObserver
{
    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    public function created(TeamInvitation $invitation): void
    {
        $this->webhooks->send(WebhookEvent::InvitationCreated, $invitation->team, [
            'team_id' => $invitation->team_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ]);
    }
}
