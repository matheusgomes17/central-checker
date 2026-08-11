<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * A read-only view over the framework's failed job table.
 *
 * Laravel ships the table without a model because the queue works through the
 * failed job provider, not Eloquent. This exists only so the operator panel
 * can list and filter what is in there — the retry and forget actions still go
 * through Artisan, so the queue keeps owning its own bookkeeping.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property Carbon $failed_at
 */
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'failed_jobs')]
#[\Illuminate\Database\Eloquent\Attributes\WithoutTimestamps]
class FailedJob extends Model
{
    /**
     * The job class the payload describes, for display.
     */
    public function displayName(): string
    {
        /** @var array{displayName?: string} $payload */
        $payload = json_decode($this->payload, true) ?: [];

        return $payload['displayName'] ?? __('Unknown job');
    }

    /**
     * The first line of the exception, which is the part worth listing.
     */
    public function exceptionSummary(): string
    {
        return str($this->exception)->before("\n")->limit(160)->toString();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }
}
