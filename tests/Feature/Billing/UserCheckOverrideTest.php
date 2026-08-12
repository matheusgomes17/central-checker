<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Team;
use App\Models\User;
use App\Models\UserCheckOverride;
use App\Support\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CheckerApi\Models\Check;
use Tests\TestCase;

/**
 * UserCheckOverride carries the three per-user levers on top of a check:
 * blocked, granted, and a custom price. These tests pin down that the levers
 * round-trip through the database and that for() finds the right row while
 * keeping isolation between users on the same team.
 */
class UserCheckOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_override_and_reads_its_levers_back(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $check = Check::factory()->create();
        // A criação do usuário troca o tenant ambiente (UserFactory chama
        // switchTeam no afterCreating) — por isso o tenant é fixado só
        // depois, e com as dependências já resolvidas para não disparar
        // outra troca durante o create() abaixo.
        resolve(TenantManager::class)->set($team);

        $created = UserCheckOverride::factory()
            ->blocked()
            ->granted()
            ->priced(500)
            ->create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'check_id' => $check->id,
            ]);

        $override = UserCheckOverride::query()->findOrFail($created->id);

        $this->assertTrue($override->blocked);
        $this->assertTrue($override->granted);
        $this->assertSame(500, $override->price_cents);
    }

    public function test_for_finds_the_override_for_the_given_team_user_and_check(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $check = Check::factory()->create();
        resolve(TenantManager::class)->set($team);

        $override = UserCheckOverride::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'check_id' => $check->id,
        ]);

        $found = UserCheckOverride::for($team, $user, $check);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($override));
    }

    public function test_for_returns_null_when_there_is_no_override(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $check = Check::factory()->create();

        $this->assertNull(UserCheckOverride::for($team, $user, $check));
    }

    public function test_for_does_not_return_an_override_belonging_to_another_user(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $check = Check::factory()->create();
        resolve(TenantManager::class)->set($team);

        UserCheckOverride::factory()->create([
            'team_id' => $team->id,
            'user_id' => $otherUser->id,
            'check_id' => $check->id,
        ]);

        $this->assertNull(UserCheckOverride::for($team, $user, $check));
    }
}
