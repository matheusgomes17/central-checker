<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\PlanSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlanSubscription only derives two answers from its two dates. These tests
 * pin down that reading for the three states the webhook can leave it in:
 * simply active, expired, and active-but-will-not-renew (canceling).
 */
class PlanSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_subscription_is_active_and_not_expiring(): void
    {
        $subscription = PlanSubscription::factory()->active()->create();

        $this->assertTrue($subscription->isActive());
        $this->assertFalse($subscription->isExpiring());
    }

    public function test_an_expired_subscription_is_not_active(): void
    {
        $subscription = PlanSubscription::factory()->expired()->create();

        $this->assertFalse($subscription->isActive());
    }

    public function test_a_canceling_subscription_is_active_and_expiring(): void
    {
        $subscription = PlanSubscription::factory()->canceling()->create();

        $this->assertTrue($subscription->isActive());
        $this->assertTrue($subscription->isExpiring());
    }
}
