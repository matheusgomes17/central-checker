<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Exceptions\TenantIsMissingException;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantManager;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Fixtures\Models\TenantResource;
use Tests\TestCase;

/**
 * The highest risk surface in the whole project: a tenant reading another
 * tenant's rows is a critical security failure, not a bug report.
 *
 * These tests run against a fixture model rather than a real one because the
 * boilerplate ships with no tenant owned domain models yet. The rules they
 * pin down are what every future model inherits from the trait.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        $this->teamA = Team::factory()->create(['name' => 'Team A']);
        $this->teamB = Team::factory()->create(['name' => 'Team B']);
    }

    public function test_reads_only_return_rows_from_the_current_tenant(): void
    {
        $this->asTenant($this->teamA, fn () => TenantResource::create(['name' => 'a-one']));
        $this->asTenant($this->teamB, fn () => TenantResource::create(['name' => 'b-one']));

        $this->asTenant($this->teamA, function (): void {
            $names = TenantResource::query()->pluck('name')->all();

            $this->assertSame(['a-one'], $names);
        });

        $this->asTenant($this->teamB, function (): void {
            $names = TenantResource::query()->pluck('name')->all();

            $this->assertSame(['b-one'], $names);
        });
    }

    /**
     * Finding by primary key is the classic leak: an id from another tenant
     * arrives in a URL and the lookup happily returns the row.
     */
    public function test_a_row_from_another_tenant_cannot_be_found_by_id(): void
    {
        $foreign = $this->asTenant($this->teamB, fn () => TenantResource::create(['name' => 'b-one']));

        $this->asTenant($this->teamA, function () use ($foreign): void {
            $this->assertNull(TenantResource::query()->find($foreign->id));
            $this->assertSame(0, TenantResource::query()->count());
            $this->assertFalse(TenantResource::query()->whereKey($foreign->id)->exists());
        });
    }

    public function test_a_row_from_another_tenant_cannot_be_updated_or_deleted(): void
    {
        $foreign = $this->asTenant($this->teamB, fn () => TenantResource::create(['name' => 'b-one']));

        $this->asTenant($this->teamA, function () use ($foreign): void {
            TenantResource::query()->whereKey($foreign->id)->update(['name' => 'hijacked']);
            TenantResource::query()->whereKey($foreign->id)->delete();
        });

        $this->assertDatabaseHas('tenant_resources', ['id' => $foreign->id, 'name' => 'b-one']);
    }

    public function test_writes_are_stamped_with_the_current_tenant(): void
    {
        $resource = $this->asTenant($this->teamA, fn () => TenantResource::create(['name' => 'a-one']));

        $this->assertSame($this->teamA->id, $resource->team_id);
    }

    /**
     * A team_id supplied by hand must not be a way around the scope. It is
     * still accepted on create — some callers legitimately know the team —
     * but the row remains invisible to any other tenant.
     */
    public function test_an_explicit_team_id_does_not_make_the_row_visible_elsewhere(): void
    {
        $this->asTenant($this->teamA, function (): void {
            TenantResource::create(['name' => 'planted', 'team_id' => $this->teamB->id]);

            $this->assertSame(0, TenantResource::query()->count());
        });

        $this->asTenant($this->teamB, function (): void {
            $this->assertSame(1, TenantResource::query()->count());
        });
    }

    /**
     * The fail-closed rule. Letting a query through with no filter would mean any
     * code path that loses the tenant quietly serves every team's rows.
     */
    public function test_no_tenant_means_no_rows(): void
    {
        $this->asTenant($this->teamA, fn () => TenantResource::create(['name' => 'a-one']));

        resolve(TenantManager::class)->set(null);

        $this->assertSame(0, TenantResource::query()->count());
    }

    public function test_creating_without_a_tenant_throws_instead_of_writing_an_orphan(): void
    {
        resolve(TenantManager::class)->set(null);

        $this->expectException(TenantIsMissingException::class);

        TenantResource::create(['name' => 'orphan']);
    }

    public function test_the_escape_hatch_sees_every_tenant(): void
    {
        $this->asTenant($this->teamA, fn () => TenantResource::create(['name' => 'a-one']));
        $this->asTenant($this->teamB, fn () => TenantResource::create(['name' => 'b-one']));

        resolve(TenantManager::class)->set(null);

        $this->assertSame(2, TenantResource::acrossTenants()->count());
        $this->assertSame(2, TenantResource::withoutTenancy(fn (): int => TenantResource::query()->count()));
    }

    /**
     * Suspension has to be temporary even when the callback blows up,
     * otherwise one exception disables tenancy for the rest of the process.
     */
    public function test_the_escape_hatch_restores_scoping_after_an_exception(): void
    {
        $this->asTenant($this->teamB, fn () => TenantResource::create(['name' => 'b-one']));

        $tenants = resolve(TenantManager::class);
        $tenants->set($this->teamA);

        try {
            $tenants->withoutTenancy(function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertFalse($tenants->isSuspended());
        $this->assertSame(0, TenantResource::query()->count());
    }

    /**
     * The manager caches its answer, so switching teams mid-request has to
     * invalidate it — otherwise the rest of the request stays on the old team.
     */
    public function test_switching_teams_moves_the_tenant(): void
    {
        $user = User::factory()->create();

        $this->teamA->memberships()->create(['user_id' => $user->id]);
        $this->teamB->memberships()->create(['user_id' => $user->id]);

        $this->actingAs($user);

        $user->switchTeam($this->teamA);
        TenantResource::create(['name' => 'a-one']);

        $user->switchTeam($this->teamB);
        TenantResource::create(['name' => 'b-one']);

        $this->assertSame(['b-one'], TenantResource::query()->pluck('name')->all());

        $user->switchTeam($this->teamA);

        $this->assertSame(['a-one'], TenantResource::query()->pluck('name')->all());
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(Team $team, Closure $callback): mixed
    {
        return resolve(TenantManager::class)->forTenant($team, $callback);
    }
}
