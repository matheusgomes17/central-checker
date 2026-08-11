<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum token that also knows which team it speaks for.
 *
 * @property int $id
 * @property int|null $team_id
 * @property string $name
 * @property array<int, string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property-read Team|null $team
 */
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'personal_access_tokens')]
class ApiToken extends PersonalAccessToken
{
    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
