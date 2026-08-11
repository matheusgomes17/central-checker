<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The phase acceptance criterion: role by action by resource, written out.
 *
 * The value here is that the table is readable. Someone reviewing what a role
 * may do reads this file rather than following a permission through an enum,
 * a policy and a package. Every cell is stated, including the ones that are
 * obviously false, because a permission that quietly appears is exactly the
 * kind that nobody notices.
 */
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array<string, array{TeamRole, array<string, bool>}>
     */
    public static function roles(): array
    {
        return [
            'owner' => [TeamRole::Owner, [
                'view' => true,
                'update' => true,
                'delete' => true,
                'leave' => false,
                'transferOwnership' => true,
                'addMember' => true,
                'updateMember' => true,
                'removeMember' => true,
                'inviteMember' => true,
                'cancelInvitation' => true,
            ]],
            'admin' => [TeamRole::Admin, [
                'view' => true,
                'update' => true,
                'delete' => false,
                'leave' => true,
                'transferOwnership' => false,
                'addMember' => false,
                'updateMember' => false,
                'removeMember' => false,
                'inviteMember' => true,
                'cancelInvitation' => true,
            ]],
            'billing' => [TeamRole::Billing, [
                'view' => true,
                'update' => false,
                'delete' => false,
                'leave' => true,
                'transferOwnership' => false,
                'addMember' => false,
                'updateMember' => false,
                'removeMember' => false,
                'inviteMember' => false,
                'cancelInvitation' => false,
            ]],
            'member' => [TeamRole::Member, [
                'view' => true,
                'update' => false,
                'delete' => false,
                'leave' => true,
                'transferOwnership' => false,
                'addMember' => false,
                'updateMember' => false,
                'removeMember' => false,
                'inviteMember' => false,
                'cancelInvitation' => false,
            ]],
        ];
    }

    /**
     * @param  array<string, bool>  $expectations
     */
    #[DataProvider('roles')]
    public function test_the_role_may_do_exactly_what_the_matrix_says(TeamRole $role, array $expectations): void
    {
        [$user, $team] = $this->member($role);

        foreach ($expectations as $action => $allowed) {
            $this->assertSame(
                $allowed,
                $user->can($action, $team),
                "Papel {$role->value} em [{$action}].",
            );
        }
    }

    /**
     * The same table applied to a team the user has nothing to do with. Every
     * cell is false — the resource axis of the matrix, and the one that turns
     * a permission bug into a tenant leak.
     *
     * @param  array<string, bool>  $expectations
     */
    #[DataProvider('roles')]
    public function test_the_role_grants_nothing_on_another_team(TeamRole $role, array $expectations): void
    {
        [$user] = $this->member($role);

        $stranger = Team::factory()->create();

        foreach (array_keys($expectations) as $action) {
            $this->assertFalse(
                $user->can($action, $stranger),
                "Papel {$role->value} não pode [{$action}] em time alheio.",
            );
        }
    }

    /**
     * A personal team has no one to hand it to and no one to leave it for, so
     * two rules answer differently there regardless of role.
     */
    public function test_a_personal_team_can_be_neither_left_nor_transferred_nor_deleted(): void
    {
        $user = User::factory()->create();
        $personal = $user->personalTeam();

        $this->assertInstanceOf(Team::class, $personal);
        $this->assertTrue($user->can('update', $personal));
        $this->assertFalse($user->can('leave', $personal));
        $this->assertFalse($user->can('transferOwnership', $personal));
        $this->assertFalse($user->can('delete', $personal));
    }

    /**
     * @return array{User, Team}
     */
    private function member(TeamRole $role): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['is_personal' => false]);

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, $role);

        return [$user, $team];
    }
}
