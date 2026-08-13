<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\AuditEvent;
use App\Enums\SystemRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\TenantManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The service, not the routes.
 *
 * The controller catches ImpersonationException and answers with a redirect,
 * which is right for a person and useless for a test: a refused impersonation
 * and a successful one both redirect. Mutation testing found what that hides —
 * eight surviving mutants, including one that dropped the team switch and one
 * that emptied the audit properties, with the HTTP tests still green.
 *
 * The session write is also duplicated: the service writes the key and the
 * controller writes it again. Going through the routes cannot tell which of
 * the two did it.
 */
class ImpersonationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->startSession();
    }

    public function test_starting_writes_the_impersonator_into_the_session(): void
    {
        [$admin, $target] = [$this->superAdmin(), User::factory()->create()];

        $this->impersonation()->start($admin, $target);

        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));
        $this->assertAuthenticatedAs($target);
    }

    public function test_starting_records_who_is_behind_the_session(): void
    {
        [$admin, $target] = [$this->superAdmin(), User::factory()->create()];

        $this->impersonation()->start($admin, $target);

        $this->assertSame(
            $admin->id,
            $this->properties(AuditEvent::ImpersonationStarted)['impersonator_id'] ?? null,
        );
    }

    public function test_stopping_records_who_was_behind_the_session(): void
    {
        [$admin, $target] = [$this->superAdmin(), User::factory()->create()];

        $impersonation = $this->impersonation();
        $impersonation->start($admin, $target);
        $impersonation->stop();

        $this->assertSame(
            $admin->id,
            $this->properties(AuditEvent::ImpersonationStopped)['impersonator_id'] ?? null,
        );
    }

    /**
     * The target's own team, not the first one that answers.
     *
     * Falling back to the personal team would drop whoever was working in a
     * shared team into a different one, and the page they were looking at
     * would 403 for a reason nobody could see.
     */
    public function test_starting_enters_the_team_the_target_was_working_in(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();
        $shared = $this->teamFor($target);

        $this->assertFalse($target->personalTeam()?->is($shared) ?? true);

        $this->impersonation()->start($admin, $target);

        $this->assertTrue($target->fresh()?->isCurrentTeam($shared));
    }

    public function test_stopping_puts_the_administrator_back_in_their_own_team(): void
    {
        $admin = $this->superAdmin();
        $adminTeam = $this->teamFor($admin);
        $target = User::factory()->create();
        $this->teamFor($target);

        $impersonation = $this->impersonation();
        $impersonation->start($admin, $target);
        $impersonation->stop();

        $this->assertTrue($admin->fresh()?->isCurrentTeam($adminTeam));

        // A coluna não é a parte perigosa. Sem invalidar o TenantManager
        // aqui, tudo que rodar depois do stop continuaria escopado no time da
        // outra pessoa pelo resto da requisição, e a próxima página seria um
        // 403 sem explicação. A URL não carrega mais slug nenhum para checar.
        $this->assertSame($adminTeam->id, resolve(TenantManager::class)->id());
    }

    /**
     * Nobody authenticated means nobody to record the stop against. The trail
     * would rather have no entry than an entry pointing at nothing.
     */
    public function test_stopping_with_no_one_authenticated_records_nothing(): void
    {
        $admin = $this->superAdmin();

        session([Impersonation::SESSION_KEY => $admin->id]);
        Auth::logout();

        $this->impersonation()->stop();

        $this->assertDatabaseMissing('activity_log', [
            'event' => AuditEvent::ImpersonationStopped->value,
        ]);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_session_value_that_is_not_an_id_resolves_to_nobody(): void
    {
        session([Impersonation::SESSION_KEY => ['not', 'an', 'id']]);

        $this->assertNull($this->impersonation()->impersonator());
    }

    /**
     * A list holding a real id is the interesting shape: `whereKey` accepts it
     * and would answer with the user, so the guard has to refuse before the
     * query and not after it.
     */
    public function test_a_list_containing_a_real_id_resolves_to_nobody(): void
    {
        $admin = $this->superAdmin();

        session([Impersonation::SESSION_KEY => [$admin->id]]);

        $this->assertNull($this->impersonation()->impersonator());
    }

    public function test_an_id_in_the_session_resolves_to_the_administrator(): void
    {
        $admin = $this->superAdmin();

        session([Impersonation::SESSION_KEY => $admin->id]);

        $this->assertTrue($admin->is($this->impersonation()->impersonator()));
    }

    /**
     * The session round-trips through a cookie, and what goes in as an integer
     * can come back as a string.
     */
    public function test_an_id_stored_as_a_string_resolves_to_the_administrator(): void
    {
        $admin = $this->superAdmin();

        session([Impersonation::SESSION_KEY => (string) $admin->id]);

        $this->assertTrue($admin->is($this->impersonation()->impersonator()));
    }

    private function impersonation(): Impersonation
    {
        return resolve(Impersonation::class);
    }

    /**
     * A non-personal team the user is working in.
     */
    private function teamFor(User $user): Team
    {
        $team = Team::factory()->create();
        $team->members()->attach($user);
        $user->assignTeamRole($team, TeamRole::Owner);
        $user->switchTeam($team);

        return $team;
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(AuditEvent $event): array
    {
        $activity = Activity::query()->where('event', $event->value)->latest('id')->first();

        $this->assertNotNull($activity, "Nenhum registro de auditoria para [{$event->value}].");

        /** @var array<string, mixed> $properties */
        $properties = $activity->properties->toArray();

        return $properties;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);

        return $user->refresh();
    }
}
