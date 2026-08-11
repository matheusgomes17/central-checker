<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The manager answers once per request and remembers.
 *
 * That is not a performance detail — it is what makes the tenant stable
 * while a request runs. Resolving again on every call would let the answer
 * change halfway through, and half a request scoped to one team and half to
 * another is the shape of a leak.
 *
 * Dropping the cached answer is deliberate and has its own trigger: sign in,
 * sign out, team switch, or a job moving to the next tenant.
 */
class TenantManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_answers_once_and_keeps_the_answer(): void
    {
        $user = User::factory()->create();
        $first = $this->teamFor($user);
        $second = Team::factory()->create();

        $this->actingAs($user);

        $manager = resolve(TenantManager::class);

        $this->assertSame($first->id, $manager->id());

        // Trocar o time do usuário em memória sem passar por switchTeam: quem
        // faz isso de verdade é qualquer código que recarregue a relação no
        // meio da requisição.
        Auth::user()?->setRelation('currentTeam', $second);

        $this->assertSame($first->id, $manager->id(), 'O tenant mudou no meio da requisição.');
    }

    public function test_forgetting_makes_it_resolve_again(): void
    {
        $user = User::factory()->create();
        $this->teamFor($user);
        $second = Team::factory()->create();

        $this->actingAs($user);

        $manager = resolve(TenantManager::class);
        $manager->id();

        Auth::user()?->setRelation('currentTeam', $second);
        $manager->forget();

        $this->assertSame($second->id, $manager->id());
    }

    private function teamFor(User $user): Team
    {
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Owner);
        $user->switchTeam($team);

        return $team;
    }
}
