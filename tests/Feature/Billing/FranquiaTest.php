<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\PlanCheck;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Franquia;
use App\Support\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Tests\TestCase;

/**
 * Franquia answers whether a team's plan covers a check and how much of the
 * period's allowance is already used. Usage is derived from CheckExecution
 * history, not a separate counter, so these tests pin down what counts:
 * only successes, only inside the current paid (or calendar) period.
 */
class FranquiaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plan_with_quota_reports_used_executions_and_has_remaining(): void
    {
        $team = $this->subscribedTeam();
        $check = Check::factory()->create();
        PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 10]);

        resolve(TenantManager::class)->set($team);
        CheckExecution::factory()->count(3)->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
        ]);

        $status = resolve(Franquia::class)->for($team, $check);

        $this->assertNotNull($status);
        $this->assertSame(10, $status->quota);
        $this->assertSame(3, $status->used);
        $this->assertFalse($status->isUnlimited());
        $this->assertTrue($status->hasRemaining());
        $this->assertSame(7, $status->remaining());
    }

    public function test_an_unlimited_plan_check_always_has_remaining(): void
    {
        $team = $this->subscribedTeam();
        $check = Check::factory()->create();
        PlanCheck::factory()->unlimited()->create(['plan' => 'pro', 'check_id' => $check->id]);

        resolve(TenantManager::class)->set($team);
        CheckExecution::factory()->count(50)->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
        ]);

        $status = resolve(Franquia::class)->for($team, $check);

        $this->assertNotNull($status);
        $this->assertTrue($status->isUnlimited());
        $this->assertNull($status->remaining());
        $this->assertTrue($status->hasRemaining());
    }

    public function test_a_check_the_plan_does_not_cover_resolves_to_null(): void
    {
        $team = $this->subscribedTeam();
        $check = Check::factory()->create();
        // Nenhum PlanCheck ligando o plano 'pro' a este check.

        resolve(TenantManager::class)->set($team);

        $status = resolve(Franquia::class)->for($team, $check);

        $this->assertNull($status);
    }

    public function test_used_counts_only_successful_executions(): void
    {
        $team = $this->subscribedTeam();
        $check = Check::factory()->create();
        PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 10]);

        resolve(TenantManager::class)->set($team);
        CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
        ]);
        CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::NoData,
        ]);
        CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Failed,
        ]);

        $status = resolve(Franquia::class)->for($team, $check);

        $this->assertNotNull($status);
        $this->assertSame(1, $status->used);
    }

    public function test_an_execution_before_the_current_period_start_does_not_count(): void
    {
        $team = Team::factory()->create();
        $subscription = PlanSubscription::factory()->active()->create([
            'team_id' => $team->id,
            'plan' => 'pro',
        ]);

        $check = Check::factory()->create();
        PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 10]);

        resolve(TenantManager::class)->set($team);

        $before = CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
        ]);
        $before->forceFill(['created_at' => $subscription->current_period_start->clone()->subDay()])->save();

        CheckExecution::factory()->create([
            'team_id' => $team->id,
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
        ]);

        $status = resolve(Franquia::class)->for($team, $check);

        $this->assertNotNull($status);
        $this->assertSame(1, $status->used);
    }

    private function subscribedTeam(): Team
    {
        $team = Team::factory()->create();

        PlanSubscription::factory()->active()->create([
            'team_id' => $team->id,
            'plan' => 'pro',
        ]);

        return $team;
    }
}
