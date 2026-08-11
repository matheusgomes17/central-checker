<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mirrors the roles and permissions declared in the enums into the database.
 *
 * The enums stay the source of truth for what exists; this only projects them
 * onto the tables the permission package reads. Running it repeatedly has to
 * be safe, because it runs on every deploy — so every write is an upsert and
 * the permission set of each role is synced rather than appended. A role that
 * loses a permission in the enum has to lose it in the database too, and
 * appending would leave the old grant behind forever.
 *
 * Roles are created with no team of their own. In the package's team mode that
 * makes them available to every team, while the assignment carries the team —
 * which is what lets four role rows serve any number of teams.
 *
 * System roles are absent on purpose. The package cannot store an assignment
 * without a team, so a super admin row here would be a definition nobody could
 * ever be given; that grant lives on the user instead.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = resolve(PermissionRegistrar::class);

        // Sem isto o pacote carimba o time atual nas linhas criadas aqui, e os
        // papéis deixariam de servir aos demais times.
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            $this->seedPermissions();
            $this->seedTeamRoles();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    private function seedPermissions(): void
    {
        foreach (TeamPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    private function seedTeamRoles(): void
    {
        foreach (TeamRole::cases() as $case) {
            $role = Role::findOrCreate($case->value, 'web');

            $role->syncPermissions(array_map(
                fn (TeamPermission $permission): string => $permission->value,
                $case->permissions(),
            ));
        }
    }
}
