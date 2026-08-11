<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A scheduler that stopped raises nothing: it simply does not run, and without
 * an outside signal the only way to notice is missing the result days later.
 */
class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_recurring_work_is_scheduled(): void
    {
        $descriptions = $this->scheduledDescriptions();

        $this->assertContains('Delete expired team invitations', $descriptions);
        $this->assertContains('Purge accounts past their retention window', $descriptions);
    }

    /**
     * The metrics dashboard stays blank forever without this: running horizon
     * does not take snapshots, this command does.
     */
    public function test_the_horizon_snapshot_is_scheduled(): void
    {
        $this->assertTrue($this->hasScheduledCommand('horizon:snapshot'));
    }

    /**
     * A heartbeat pointing nowhere only creates the impression of being
     * watched, so it is not scheduled at all when no URL is set.
     */
    public function test_the_heartbeat_stays_off_without_a_url(): void
    {
        $this->assertNotContains('Heartbeat do agendador', $this->scheduledDescriptions());
    }

    /**
     * @return array<int, string>
     */
    private function scheduledDescriptions(): array
    {
        $descriptions = [];

        foreach (resolve(Schedule::class)->events() as $event) {
            if (is_string($event->description)) {
                $descriptions[] = $event->description;
            }
        }

        return $descriptions;
    }

    private function hasScheduledCommand(string $needle): bool
    {
        foreach (resolve(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return true;
            }
        }

        return false;
    }
}
