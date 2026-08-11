<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin TeamInvitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'expired' => $this->isExpired(),
            // O código é a credencial de entrada no time. Ele existe no banco,
            // mas nunca sai por aqui: quem tem a lista de convites teria a
            // lista de chaves.
        ];
    }
}
