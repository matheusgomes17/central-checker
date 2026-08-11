<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TenantAware;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The base every job in this application starts from.
 *
 * Exists to make the conventions the default rather than something each job
 * remembers: the tenant travels with the job, retries back off instead of
 * hammering whatever just failed, and the job gives up rather than retrying
 * forever.
 *
 * ShouldBeUnique is deliberately *not* here. Uniqueness is a property of what
 * a job does — two exports of the same report should collapse, two invitation
 * e-mails to different people should not — so it is declared per job, by
 * implementing the interface.
 *
 * @see ShouldBeUnique
 */
abstract class TenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    /**
     * Below the supervisor timeout, which is below retry_after. Out of that
     * order a job is retried while it is still running.
     */
    public int $timeout = 120;

    public int $tries = 3;

    /**
     * Growing pauses between attempts.
     *
     * A fixed retry hammers whatever just failed, which is usually the thing
     * least able to take it.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Stop trying after this, however many attempts are left.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    final public function handle(): void
    {
        $this->restoreTenant();

        $this->run();
    }

    /**
     * The job itself.
     */
    abstract protected function run(): void;
}
