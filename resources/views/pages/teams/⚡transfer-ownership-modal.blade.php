<?php

use App\Actions\Teams\TransferTeamOwnership;
use App\Concerns\PasswordValidationRules;
use App\Enums\TeamRole;
use App\Exceptions\OwnershipTransferException;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public Team $team;

    public ?int $newOwnerId = null;

    public string $password = '';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    /**
     * Members who could take the team over — everyone but the current owner.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed]
    public function candidates(): array
    {
        return $this->team->memberships()
            ->with('user')
            ->where('user_id', '!=', auth()->id())
            ->get()
            ->map(fn ($membership): array => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
            ])
            ->all();
    }

    public function transfer(TransferTeamOwnership $transfer): void
    {
        Gate::authorize('transferOwnership', $this->team);

        // Confirmação de senha: entregar o time é irreversível pelo lado de
        // quem entrega, e uma sessão esquecida aberta não deveria bastar.
        $this->validate([
            'newOwnerId' => ['required', 'integer'],
            'password' => $this->currentPasswordRules(),
        ]);

        try {
            $transfer->handle($this->team, auth()->user(), User::findOrFail($this->newOwnerId));
        } catch (OwnershipTransferException $e) {
            throw ValidationException::withMessages(['newOwnerId' => $e->getMessage()]);
        }

        $this->reset('password', 'newOwnerId');

        $this->dispatch('close-modal', name: 'transfer-ownership');

        Flux::toast(variant: 'success', text: __('Team ownership transferred.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<flux:modal name="transfer-ownership" focusable class="max-w-lg">
    <form wire:submit="transfer" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Transfer ownership') }}</flux:heading>
            <flux:subheading>
                {{ __('The new owner takes full control of this team. You stay on as an administrator.') }}
            </flux:subheading>
        </div>

        @if ($this->candidates === [])
            <flux:text>{{ __('Invite someone to the team before transferring it.') }}</flux:text>
        @else
            <flux:select wire:model="newOwnerId" :label="__('New owner')" required>
                <flux:select.option value="">{{ __('Select a member') }}</flux:select.option>
                @foreach ($this->candidates as $candidate)
                    <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="password" :label="__('Password')" type="password" required viewable />
        @endif

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            @if ($this->candidates !== [])
                <flux:button variant="danger" type="submit" data-test="transfer-ownership-confirm">
                    {{ __('Transfer ownership') }}
                </flux:button>
            @endif
        </div>
    </form>
</flux:modal>
