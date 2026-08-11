<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Jobs\TenantJob;
use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Models\TenantResource;
use Tests\TestCase;

/**
 * A queued job runs with no request, no session and no signed-in user, so the
 * tenant has nothing to resolve from. Combined with a scope that fails closed,
 * that means a job which forgets the tenant does not read the wrong team's
 * rows — it reads nothing at all, and quietly does nothing.
 *
 * These tests are about the tenant surviving the trip to the queue and back.
 */
class TenantJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::create('tenant_resources', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_the_job_remembers_the_tenant_it_was_created_in(): void
    {
        $team = Team::factory()->create();

        $job = resolve(TenantManager::class)->forTenant($team, fn (): CountsResources => (new CountsResources)->forTenant());

        $this->assertSame($team->id, $job->tenantId);
    }

    /**
     * The point of the whole trait: what the job reads is the team it was
     * dispatched for, not whatever happens to be current when it runs.
     */
    public function test_the_job_reads_its_own_tenant_when_it_runs(): void
    {
        $tenants = resolve(TenantManager::class);

        [$teamA, $teamB] = [Team::factory()->create(), Team::factory()->create()];

        $tenants->forTenant($teamA, fn () => TenantResource::create(['name' => 'a-one']));
        $tenants->forTenant($teamB, function (): void {
            TenantResource::create(['name' => 'b-one']);
            TenantResource::create(['name' => 'b-two']);
        });

        $job = $tenants->forTenant($teamB, fn (): CountsResources => (new CountsResources)->forTenant());

        // Roda fora de qualquer tenant, como acontece num worker.
        $tenants->set(null);

        $job->handle();

        $this->assertSame(2, CountsResources::$counted);
    }

    /**
     * Without the tenant making the trip, the scope fails closed and the job sees
     * an empty database — the failure mode that looks like "nothing to do".
     */
    public function test_a_job_without_a_tenant_sees_nothing(): void
    {
        $tenants = resolve(TenantManager::class);

        $team = Team::factory()->create();

        $tenants->forTenant($team, fn () => TenantResource::create(['name' => 'a-one']));

        $job = new CountsResources;

        $tenants->set(null);

        $job->handle();

        $this->assertSame(0, CountsResources::$counted);
    }

    public function test_jobs_are_tagged_with_their_team(): void
    {
        $team = Team::factory()->create();

        $job = resolve(TenantManager::class)->forTenant($team, fn (): CountsResources => (new CountsResources)->forTenant());

        $this->assertSame(['CountsResources', 'team:'.$team->id], $job->tags());
    }

    /**
     * A fixed retry hammers whatever just failed, which is usually the thing
     * least able to take it.
     */
    public function test_retries_back_off_and_eventually_give_up(): void
    {
        $job = new CountsResources;

        $this->assertSame([10, 60, 300], $job->backoff());
        $this->assertSame(3, $job->tries);
        $this->assertTrue($job->retryUntil() > now());
    }

    /**
     * The timeout chain has to stay ordered: job below supervisor below
     * retry_after. Out of order, a job is retried while it is still running.
     */
    public function test_the_timeout_sits_below_the_supervisor_and_the_retry_window(): void
    {
        $job = new CountsResources;

        $supervisor = Config::integer('horizon.defaults.supervisor-default.timeout');
        $retryAfter = Config::integer('queue.connections.redis.retry_after');

        $this->assertLessThan($supervisor, $job->timeout);
        $this->assertLessThan($retryAfter, $supervisor);
    }

    public function test_the_job_actually_queues(): void
    {
        Queue::fake();

        dispatch(new CountsResources);

        Queue::assertPushed(CountsResources::class);
    }

    public function test_jobs_can_be_chained_and_batched(): void
    {
        Bus::fake();

        Bus::batch([new CountsResources, new CountsResources])->dispatch();

        Bus::assertBatchCount(1);
    }
}

/**
 * A stand-in job: counts the tenant-scoped rows it can see.
 */
class CountsResources extends TenantJob
{
    public static int $counted = 0;

    protected function run(): void
    {
        self::$counted = TenantResource::query()->count();
    }
}
