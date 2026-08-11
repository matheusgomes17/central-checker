<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\WebhookEvent;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;

/**
 * Where a team wants its events delivered.
 *
 * The secret is encrypted rather than hashed, which is the opposite of an API
 * token and for a reason: the customer has to read it more than once. They
 * paste it into their own verifier, and they paste it again when they rebuild
 * that service. A hash cannot be read back, so hashing here would mean the
 * only copy lives in whatever the customer wrote down.
 *
 * @property int $id
 * @property int $team_id
 * @property string $url
 * @property string|null $description
 * @property string $secret
 * @property array<int, string> $events
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_delivered_at
 * @property int|null $last_status
 * @property int $consecutive_failures
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable(['url', 'description', 'events', 'is_active'])]
#[\Illuminate\Database\Eloquent\Attributes\Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * After this many failures in a row the endpoint is switched off.
     *
     * An endpoint that has been gone for a week is not coming back on the next
     * attempt, and retrying it forever spends queue capacity that paying
     * deliveries need. Switching it off is visible in the interface; silently
     * dropping the events would not be.
     */
    public const int FAILURE_LIMIT = 10;

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    public function subscribesTo(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events, true);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }
}
