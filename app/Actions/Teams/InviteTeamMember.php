<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Exceptions\PlanLimitException;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Support\Billing\TeamBilling;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

/**
 * Creates an invitation and sends it.
 *
 * One place because there are now three callers — the invite dialog, the CSV
 * import, and the API — and the expiry window and the email are the sort of
 * thing that quietly drifts apart when each caller writes its own version. A
 * team that invites through the API and gets no email, or gets a different
 * deadline than the dialog gives, would be right to call that a bug.
 */
class InviteTeamMember
{
    public function handle(Team $team, string $email, TeamRole $role, User $invitedBy): TeamInvitation
    {
        $billing = TeamBilling::for($team);

        // Aqui e não em middleware: o convite pela tela trafega pela rota do
        // Livewire, então middleware de rota nunca cobriria essa porta. As três
        // — modal, importação e API — passam por esta action.
        if (! $billing->withinLimit('members')) {
            throw PlanLimitException::reached('members', $billing->plan(), $billing->plan()->limit('members') ?? 0);
        }

        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role,
            'invited_by' => $invitedBy->getKey(),
            'expires_at' => now()->addDays(Config::integer('teams.invitation_expires_days')),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        return $invitation;
    }
}
