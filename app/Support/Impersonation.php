<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AuditEvent;
use App\Exceptions\ImpersonationException;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a platform administrator act as another user, and remembers who.
 *
 * The whole feature is one person using another person's account, so two
 * things are not optional: every start and stop is written to the audit trail,
 * and the interface says so while it is happening. Silent impersonation is
 * indistinguishable from a compromised account, both to the user and to
 * whoever reads the logs afterwards.
 *
 * The original user id is held in the session. A cookie or a token would
 * survive the session it belongs to, and an impersonation that outlives its
 * session is one nobody remembers starting.
 */
class Impersonation
{
    public const string SESSION_KEY = 'impersonation.impersonator_id';

    public function __construct(
        private readonly Session $session,
        private readonly Audit $audit,
    ) {}

    public function isActive(): bool
    {
        return $this->session->has(self::SESSION_KEY);
    }

    /**
     * The administrator behind the current session, if any.
     */
    public function impersonator(): ?User
    {
        $id = $this->session->get(self::SESSION_KEY);

        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        return User::query()->whereKey($id)->first();
    }

    public function start(User $impersonator, User $target): void
    {
        if (! $impersonator->isSuperAdmin()) {
            throw ImpersonationException::notPermitted();
        }

        if ($impersonator->is($target)) {
            throw ImpersonationException::self();
        }

        // Sem isto, entrar como outro super-admin e voltar embaralha quem é o
        // impersonador de quem — e a trilha deixa de responder quem começou.
        if ($this->isActive()) {
            throw ImpersonationException::alreadyActive();
        }

        // O alvo não pode ser alguém que também passa por toda a autorização:
        // seria uma forma de um super-admin agir sob a identidade de outro,
        // que é exatamente o que a trilha existe para tornar impossível de
        // esconder.
        if ($target->isSuperAdmin()) {
            throw ImpersonationException::targetIsPrivileged();
        }

        $this->audit->record(AuditEvent::ImpersonationStarted, $target, [
            'impersonator_id' => $impersonator->id,
        ], $impersonator);

        Auth::login($target);

        $this->enterTeamContextOf($target);

        $this->session->put(self::SESSION_KEY, $impersonator->id);
    }

    /**
     * Move the request into the user's own team.
     *
     * Without this the session keeps the previous person's team in the URL
     * defaults and in the tenant, so the very first page load is a request for
     * a team the current user does not belong to — a 403 that looks like a bug
     * and is really the two identities disagreeing.
     */
    private function enterTeamContextOf(User $user): void
    {
        $team = $user->currentTeam ?? $user->personalTeam();

        if ($team !== null) {
            $user->switchTeam($team);
        }
    }

    public function stop(): void
    {
        $impersonator = $this->impersonator();

        if (! $impersonator instanceof User) {
            throw ImpersonationException::notActive();
        }

        $target = Auth::user();

        $this->session->forget(self::SESSION_KEY);

        Auth::login($impersonator);

        $this->enterTeamContextOf($impersonator);

        if ($target instanceof User) {
            $this->audit->record(AuditEvent::ImpersonationStopped, $target, [
                'impersonator_id' => $impersonator->id,
            ], $impersonator);
        }
    }
}
