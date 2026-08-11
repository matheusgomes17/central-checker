<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Actions\Teams\InviteTeamMember;
use App\Enums\TeamRole;
use App\Jobs\TenantJob;
use App\Models\Team;
use App\Models\User;
use Illuminate\Bus\Batchable;

/**
 * Invites one chunk of addresses from an uploaded list.
 *
 * Chunked rather than one job per row: a thousand rows would be a thousand
 * jobs whose overhead dwarfs the work, and a thousand rows in one job is a
 * job that fails at row 998 with no way to resume. A chunk is small enough to
 * retry and big enough to be worth dispatching.
 *
 * Batchable, so the batch reports how far it got. Progress is not decoration
 * here — an import that says nothing for two minutes reads as broken, and the
 * person retries it.
 */
class ImportInvitationChunk extends TenantJob
{
    use Batchable;

    /**
     * @param  array<int, string>  $emails
     */
    public function __construct(
        private readonly array $emails,
        private readonly TeamRole $role,
        private readonly int $invitedBy,
    ) {}

    protected function run(): void
    {
        // Um lote cancelado não deve continuar convidando. Sem isto, cancelar
        // pararia de enfileirar mas não pararia o que já está na fila.
        if ($this->batch()?->cancelled() ?? false) {
            return;
        }

        $team = Team::query()->whereKey($this->tenantId)->first();

        if (! $team instanceof Team) {
            return;
        }

        $invitedBy = User::query()->find($this->invitedBy);

        if (! $invitedBy instanceof User) {
            return;
        }

        foreach ($this->emails as $email) {
            $this->invite($team, $invitedBy, $email);
        }
    }

    /**
     * Deliberately does not catch PlanLimitException.
     *
     * The batch's entry point already checked the whole list against the
     * ceiling; this can only fire under concurrency — another import or an
     * invite landing between dispatch and execution. That is rare enough, and
     * important enough, that it should fail the job loudly rather than
     * silently stop inviting past the ceiling.
     */
    private function invite(Team $team, User $invitedBy, string $email): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Já é membro, ou já foi convidado e o convite continua de pé: os dois
        // casos são "nada a fazer", e reconvidar seria mandar um segundo
        // e-mail para quem já recebeu o primeiro. Convite vencido não é um
        // deles: o assento voltou a estar livre, e reconvidar é o que a
        // planilha está pedindo.
        if ($team->members()->where('users.email', $email)->exists()) {
            return;
        }

        if ($team->invitations()->where('email', $email)->pending()->exists()) {
            return;
        }

        resolve(InviteTeamMember::class)->handle($team, $email, $this->role, $invitedBy);
    }
}
