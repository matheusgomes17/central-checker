<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin User
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        // Lido do token, e não do TenantManager: o resource é serializado
        // depois do controller, e depender de estado ambiente para decidir o
        // significado de um campo é frágil — foi assim que este campo saiu
        // nulo na primeira versão.
        $token = $request->user()?->currentAccessToken();
        $team = $token instanceof ApiToken ? $token->team : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // O papel é sempre o do time desta requisição. Devolver o papel
            // "do usuário" sem dizer em qual time seria uma resposta que muda
            // de significado conforme quem pergunta.
            'role' => $team instanceof Team ? $this->teamRole($team)?->value : null,
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
