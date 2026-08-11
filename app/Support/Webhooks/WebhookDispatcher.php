<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use App\Support\TenantManager;
use Illuminate\Support\Str;

/**
 * The one place that decides who hears about an event.
 *
 * Always queued, never inline. A webhook talks to a server we do not control,
 * so doing it during the request makes our response time depend on theirs — an
 * endpoint that hangs for thirty seconds would hang the person who invited a
 * teammate.
 *
 * The team is a parameter and not the current tenant, which is the whole point
 * of this class. Reading the tenant here looks equivalent and is not: someone
 * accepting an invitation to team B is browsing as team A at that moment, and
 * a dispatcher that trusted the tenant would post team B's event to team A's
 * endpoints — a customer receiving another customer's events.
 */
final readonly class WebhookDispatcher
{
    public function __construct(private TenantManager $tenants) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(WebhookEvent $event, Team $team, array $data): void
    {
        $endpoints = $this->tenants->forTenant($team, fn (): iterable => WebhookEndpoint::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event)));

        foreach ($endpoints as $endpoint) {
            dispatch(new DeliverWebhook($endpoint, [
                // Id estável entre as tentativas: é com ele que o receptor
                // descarta a entrega repetida que um timeout do lado dele
                // provoca.
                'id' => (string) Str::uuid7(),
                'type' => $event->value,
                'created_at' => now()->toIso8601String(),
                'data' => $data,
            ]));
        }
    }
}
