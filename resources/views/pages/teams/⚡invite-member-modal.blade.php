<?php

use App\Actions\Teams\InviteTeamMember;
use App\Enums\TeamRole;
use App\Exceptions\PlanLimitException;
use App\Models\Team;
use App\Rules\UniqueTeamInvitation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function createInvitation(): void
    {
        Gate::authorize('inviteMember', $this->team);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->team)],
            'inviteRole' => ['required', 'string', Rule::enum(TeamRole::class)],
        ]);

        try {
            app(InviteTeamMember::class)->handle(
                $this->team,
                $validated['inviteEmail'],
                TeamRole::from($validated['inviteRole']),
                Auth::user(),
            );
        } catch (PlanLimitException $e) {
            // Mesmo padrão de ⚡delete-team-modal: erro de formulário em vez de
            // deixar a exceção subir. A mensagem já nomeia plano e teto, então
            // vira o erro sem reescrita — quem recusa aqui é o plano, não um bug.
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->reset('inviteEmail', 'inviteRole');
        $this->dispatch('close-modal', name: 'invite-member');

        Flux::toast(variant: 'success', text: __('Invitation sent.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }

    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }
}; ?>

<flux:modal name="invite-member" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="createInvitation" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Invite a team member') }}</flux:heading>
            <flux:subheading>{{ __('Send an invitation to join this team.') }}</flux:subheading>
        </div>

        <div class="space-y-4">
            <flux:input wire:model="inviteEmail" type="email" :label="__('Email address')" required data-test="invite-email" />

            <flux:select wire:model="inviteRole" :label="__('Role')" data-test="invite-role">
                @foreach ($this->availableRoles as $role)
                    <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="invite-submit">{{ __('Send invitation') }}</flux:button>
        </div>
    </form>
</flux:modal>
