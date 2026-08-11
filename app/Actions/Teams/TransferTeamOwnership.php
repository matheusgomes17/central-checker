<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditEvent;
use App\Enums\TeamRole;
use App\Exceptions\OwnershipTransferException;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Hands a team over to another member.
 *
 * The invariant worth naming: a team has exactly one owner, before and after.
 * Promoting the new owner without demoting the old one leaves two, and every
 * "is the owner" check in the code silently starts meaning "is an owner".
 * Both writes happen in one transaction for the same reason — a half applied
 * transfer is a team with two owners or none.
 */
class TransferTeamOwnership
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Team $team, User $currentOwner, User $newOwner): void
    {
        if (! $currentOwner->ownsTeam($team)) {
            throw OwnershipTransferException::notTheOwner();
        }

        if ($currentOwner->is($newOwner)) {
            throw OwnershipTransferException::sameUser();
        }

        if (! $newOwner->belongsToTeam($team)) {
            throw OwnershipTransferException::notAMember();
        }

        // Um time pessoal é a conta da pessoa com outro nome. Transferir
        // deixaria o dono original dentro do "pessoal" de outra pessoa.
        if ($team->is_personal) {
            throw OwnershipTransferException::personalTeam();
        }

        DB::transaction(function () use ($team, $currentOwner, $newOwner): void {
            $newOwner->assignTeamRole($team, TeamRole::Owner);

            // O dono anterior vira admin, não fica sem papel: quem acabou de
            // entregar o time ainda precisa administrá-lo, e removê-lo seria
            // uma saída disfarçada de transferência.
            $currentOwner->assignTeamRole($team, TeamRole::Admin);

            $this->audit->record(AuditEvent::TeamOwnershipTransferred, $team, [
                'from_user_id' => $currentOwner->id,
                'to_user_id' => $newOwner->id,
            ], $currentOwner);
        });
    }
}
