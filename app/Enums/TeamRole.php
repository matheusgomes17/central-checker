<?php

declare(strict_types=1);

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Billing = 'billing';

    /**
     * Get the display label for the role, translated to the current locale.
     * The API keeps exposing the raw enum value; only screens use this.
     */
    public function label(): string
    {
        return __(ucfirst($this->value));
    }

    /**
     * Get all the permissions for this role.
     *
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => [
                TeamPermission::UpdateTeam,
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
                TeamPermission::ViewBilling,
                TeamPermission::ManageWebhooks,
            ],
            // Existe para dar acesso à cobrança sem dar acesso ao time. Quem
            // paga a conta não precisa poder convidar nem remover ninguém.
            self::Billing => [
                TeamPermission::ViewBilling,
                TeamPermission::ManageBilling,
            ],
            self::Member => [],
        };
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    /**
     * Get the hierarchy level for this role.
     * Higher numbers indicate higher privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            // Billing e Member não se ordenam entre si: um vê cobrança e o
            // outro não vê nada além do próprio trabalho. O nível existe para
            // o "pelo menos este papel" das rotas, e nesse eixo os dois são o
            // piso.
            self::Billing => 2,
            self::Member => 1,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get the roles that can be assigned to team members (excludes Owner).
     *
     * Owner is left out because becoming the owner is not a role assignment:
     * it is handing the team over, and that has an action of its own.
     *
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        /** @var array<int, array{value: string, label: string}> $roles */
        $roles = collect(self::cases())
            ->filter(fn (self $role): bool => $role !== self::Owner)
            ->map(fn (self $role): array => ['value' => $role->value, 'label' => $role->label()])
            ->values()
            ->toArray();

        return $roles;
    }
}
