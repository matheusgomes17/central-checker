<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\PlanCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CheckerApi\Models\Check;
use Tests\TestCase;

/**
 * PlanCheck ties a plan to a check with a quota. These tests pin down the
 * two readings an allowance can have: a numeric limit, or null for unlimited.
 */
class PlanCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plan_check_with_a_quota_is_not_unlimited(): void
    {
        $planCheck = PlanCheck::factory()->create(['quota' => 100]);

        $this->assertFalse($planCheck->isUnlimited());
        $this->assertSame(100, $planCheck->quota);
    }

    public function test_the_unlimited_state_has_no_quota(): void
    {
        $planCheck = PlanCheck::factory()->unlimited()->create();

        $this->assertTrue($planCheck->isUnlimited());
        $this->assertNull($planCheck->quota);
    }

    public function test_the_check_relation_resolves_to_the_created_check(): void
    {
        $check = Check::factory()->create();

        $planCheck = PlanCheck::factory()->create(['check_id' => $check->id]);

        $this->assertTrue($planCheck->check->is($check));
    }
}
