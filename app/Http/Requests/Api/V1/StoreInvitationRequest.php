<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Rules\UniqueTeamInvitation;
use App\Support\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    /**
     * The role list is the assignable one, which leaves out Owner.
     *
     * A team has one owner and it changes by transfer, not by invitation.
     * Accepting it here would let a write token mint a second owner.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $team = resolve(TenantManager::class)->current();

        return [
            'email' => [
                'required', 'string', 'email', 'max:255',
                ...($team instanceof Team ? [new UniqueTeamInvitation($team)] : []),
            ],
            'role' => ['required', 'string', Rule::in(array_column(TeamRole::assignable(), 'value'))],
        ];
    }
}
