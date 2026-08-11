<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_creates_every_role_declared_in_the_enums(): void
    {
        // Papéis de sistema não entram: o pacote não guarda atribuição sem
        // time, então um super-admin aqui seria definição que ninguém poderia
        // receber.
        $expected = array_map(fn (TeamRole $role): string => $role->value, TeamRole::cases());

        $this->assertEqualsCanonicalizing($expected, Role::query()->pluck('name')->all());
    }

    public function test_it_creates_every_permission_declared_in_the_enum(): void
    {
        $expected = array_map(fn (TeamPermission $p): string => $p->value, TeamPermission::cases());

        $this->assertEqualsCanonicalizing($expected, Permission::query()->pluck('name')->all());
    }

    /**
     * Roles are created with no team of their own so that one row serves every
     * team; it is the assignment that carries the team. A role stamped with a
     * team would be invisible to all the others.
     */
    public function test_roles_belong_to_no_team_in_particular(): void
    {
        $this->assertSame(0, Role::query()->whereNotNull('team_id')->count());
    }

    public function test_each_role_gets_exactly_the_permissions_its_enum_declares(): void
    {
        foreach (TeamRole::cases() as $case) {
            $expected = array_map(fn (TeamPermission $p): string => $p->value, $case->permissions());

            $actual = Role::findByName($case->value)->permissions->pluck('name')->all();

            $this->assertEqualsCanonicalizing($expected, $actual, "Papel {$case->value}.");
        }
    }

    /**
     * The seeder runs on every deploy, so running it twice must not duplicate
     * anything.
     */
    public function test_running_it_again_changes_nothing(): void
    {
        $roles = Role::query()->count();
        $permissions = Permission::query()->count();
        $grants = Role::findByName(TeamRole::Admin->value)->permissions->count();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($roles, Role::query()->count());
        $this->assertSame($permissions, Permission::query()->count());
        $this->assertSame($grants, Role::findByName(TeamRole::Admin->value)->permissions()->count());
    }

    /**
     * Syncing rather than appending is the point: a permission dropped from
     * the enum has to disappear from the database. Appending would leave the
     * old grant in place forever, and the enum would stop describing what the
     * application actually allows.
     */
    public function test_it_removes_a_grant_that_no_longer_exists_in_the_enum(): void
    {
        $admin = Role::findByName(TeamRole::Admin->value);
        $admin->givePermissionTo(TeamPermission::DeleteTeam->value);

        $this->assertTrue($admin->fresh()->hasPermissionTo(TeamPermission::DeleteTeam->value));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse($admin->fresh()->hasPermissionTo(TeamPermission::DeleteTeam->value));
    }
}
