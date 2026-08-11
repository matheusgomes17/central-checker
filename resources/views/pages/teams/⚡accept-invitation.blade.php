<?php

use App\Models\TeamInvitation;
use App\Notifications\Teams\MemberJoined;
use Illuminate\Support\Facades\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Teams')] class extends Component {
    public TeamInvitation $invitation;

    public function mount(TeamInvitation $invitation): void
    {
        $this->invitation = $invitation;

        $this->acceptInvitation();
    }

    public function acceptInvitation(): void
    {
        $user = Auth::user();

        $this->validateInvitation($user, $this->invitation);

        DB::transaction(function () use ($user) {
            $team = $this->invitation->team;

            $team->memberships()->firstOrCreate(['user_id' => $user->id]);

            $user->assignTeamRole($team, $this->invitation->role);

            $this->invitation->update(['accepted_at' => now()]);

            // Avisa quem já estava no time. Depois do commit, para não
            // notificar sobre uma entrada que a transação ainda pode desfazer.
            $existing = $team->members()->where('users.id', '!=', $user->id)->get();

            DB::afterCommit(fn () => Notification::send($existing, new MemberJoined($team, $user)));

            $user->switchTeam($team);
        });

        session()->flash('team-invitation-accepted', true);

        $this->redirectRoute('dashboard', navigate: true);
    }

    private function validateInvitation(User $user, TeamInvitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has already been accepted.')],
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has expired.')],
            ]);
        }

        if (Str::lower($invitation->email) !== Str::lower($user->email)) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation was sent to a different email address.')],
            ]);
        }
    }
}; ?>

<div></div>
