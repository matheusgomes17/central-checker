<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Exceptions\InvitationResendException;
use App\Exceptions\PlanLimitException;
use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Support\Billing\TeamBilling;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

/**
 * Sends an invitation email again and renews its window.
 *
 * Renewing matters: the usual reason to resend is that the first email was
 * lost or ignored long enough for the invitation to lapse, and sending a lapsed
 * one again without moving the deadline changes nothing.
 *
 * The code is deliberately left alone. Sending again means "you did not get
 * it", not "that link is compromised" — rotating would break the copy someone
 * did receive for no gain. To revoke, cancel the invitation instead.
 *
 * Renewing a lapsed one is an addition against the plan's ceiling, and is
 * refused like any other when there is no seat for it.
 */
class ResendTeamInvitation
{
    public function handle(TeamInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw InvitationResendException::alreadyAccepted();
        }

        $cooldown = Config::integer('teams.invitation_resend_cooldown_minutes');

        if ($invitation->updated_at?->isAfter(now()->subMinutes($cooldown))) {
            throw InvitationResendException::tooSoon($cooldown);
        }

        // Um convite vencido já devolveu o assento — o contador parou de somá-lo
        // quando ele venceu. Renovar o prazo o ocupa de novo, então isto é uma
        // adição e pergunta o teto como qualquer outra. Um convite ainda de pé
        // nunca soltou o assento dele, e reenviar não pede um segundo.
        $billing = TeamBilling::for($invitation->team);

        if ($invitation->isExpired() && ! $billing->withinLimit('members')) {
            throw PlanLimitException::reached('members', $billing->plan(), $billing->plan()->limit('members') ?? 0);
        }

        // Update pelo builder, não save() no model. Dois cliques no mesmo
        // segundo produzem o mesmo expires_at, o model não fica sujo, o save()
        // não escreve nada — e como o cooldown lê updated_at, ele nunca
        // engataria. O update sempre escreve e carimba updated_at.
        $invitation->newQuery()->whereKey($invitation->getKey())->update([
            'expires_at' => now()->addDays(Config::integer('teams.invitation_expires_days')),
        ]);

        $invitation->refresh();

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));
    }
}
