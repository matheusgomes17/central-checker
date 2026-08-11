<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use RuntimeException;
use Tests\TestCase;

/**
 * The seeder exists so someone can see the application working on the first
 * day, and `bin/setup` runs it. Since that script has to be safe to run twice,
 * the property worth testing is not "it creates rows" but "running it again
 * creates nothing new".
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_creates_the_teams_people_and_subscription_states(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(4, Team::query()->where('is_personal', false)->count());
        $this->assertSame(14, User::query()->count());

        foreach (['active', 'trialing', 'past_due'] as $status) {
            $this->assertDatabaseHas('subscriptions', ['stripe_status' => $status]);
        }

        $this->assertSame(2, TeamInvitation::query()->whereNull('accepted_at')->count());
    }

    public function test_it_seeds_a_team_above_its_plan_ceiling(): void
    {
        $this->seed(DemoSeeder::class);

        $hooli = Team::query()->where('slug', 'hooli')->firstOrFail();
        $usage = TeamBilling::for($hooli)->usage('members');

        $this->assertGreaterThan($usage['limit'], $usage['used']);
    }

    public function test_a_person_can_hold_a_different_role_in_each_team(): void
    {
        $this->seed(DemoSeeder::class);

        $joao = User::query()->where('email', 'joao@example.test')->firstOrFail();
        $acme = Team::query()->where('slug', 'acme')->firstOrFail();
        $globex = Team::query()->where('slug', 'globex')->firstOrFail();

        $this->assertSame(TeamRole::Member, $joao->teamRole($acme));
        $this->assertSame(TeamRole::Admin, $joao->teamRole($globex));
    }

    public function test_running_it_twice_creates_nothing_new(): void
    {
        $this->seed(DemoSeeder::class);

        $counts = $this->counts();

        $this->seed(DemoSeeder::class);

        $this->assertSame($counts, $this->counts());
    }

    /**
     * The path `bin/setup` and `migrate --seed` actually walk.
     *
     * The DatabaseSeeder shipped with `WithoutModelEvents`, and model events
     * carry correctness here: invitation code, team slug and tenant stamp are
     * born in `creating`. Calling the DemoSeeder directly skips that seeder and
     * would not catch it. This goes through it.
     */
    public function test_the_database_seeder_runs_end_to_end(): void
    {
        $this->seed();

        $this->assertSame(2, TeamInvitation::query()->count());
        $this->assertSame(0, TeamInvitation::query()->whereNull('code')->count());
        $this->assertSame(0, Team::query()->whereNull('slug')->count());
    }

    /**
     * `db:seed` already asks for confirmation in production, but `--force`
     * walks past it, and `--force` is what every deploy script uses. The guard
     * is called directly here because that is the path it covers.
     */
    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        new DemoSeeder()->run();
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'users' => User::query()->count(),
            'teams' => Team::query()->count(),
            'memberships' => Membership::query()->count(),
            'subscriptions' => Subscription::query()->count(),
            'invitations' => TeamInvitation::query()->count(),
        ];
    }
}
