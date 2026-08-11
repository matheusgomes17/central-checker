<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Billing\ActivatePlan;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Plan;
use App\Support\Billing\Subscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ActivatePlan is what the payment webhook calls once a Pix plan charge is
 * confirmed. There is always at most one current subscription row per team,
 * so every case here is either creating that row or updating it in place —
 * never inserting a second one.
 */
class ActivatePlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_with_no_subscription_gets_a_new_active_one(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::find('pro');

        $subscription = resolve(ActivatePlan::class)->handle($team, $plan);

        $this->assertSame('pro', $subscription->plan);
        $this->assertNull($subscription->canceled_at);
        $this->assertEqualsWithDelta(
            now()->addDays($plan->periodDays)->getTimestamp(),
            $subscription->current_period_end->getTimestamp(),
            2,
        );
        $this->assertSame('pro', resolve(Subscriptions::class)->active($team)?->plan);
    }

    public function test_paying_for_the_same_plan_again_extends_the_period(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::find('pro');

        $originalEnd = now()->addDays(10);

        PlanSubscription::factory()->create([
            'team_id' => $team->id,
            'plan' => 'pro',
            'current_period_start' => now()->subDays(20),
            'current_period_end' => $originalEnd,
            'canceled_at' => now(),
        ]);

        $subscription = resolve(ActivatePlan::class)->handle($team, $plan);

        $this->assertSame('pro', $subscription->plan);
        $this->assertNull($subscription->canceled_at);
        $this->assertEqualsWithDelta(
            $originalEnd->clone()->addDays($plan->periodDays)->getTimestamp(),
            $subscription->current_period_end->getTimestamp(),
            2,
        );

        $this->assertSame(1, PlanSubscription::query()->where('team_id', $team->id)->count());
    }

    public function test_paying_for_a_different_plan_switches_it_in_place(): void
    {
        $team = Team::factory()->create();

        PlanSubscription::factory()->create([
            'team_id' => $team->id,
            'plan' => 'pro',
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
            'canceled_at' => now(),
        ]);

        $business = Plan::find('business');

        $subscription = resolve(ActivatePlan::class)->handle($team, $business);

        $this->assertSame('business', $subscription->plan);
        $this->assertNull($subscription->canceled_at);
        $this->assertEqualsWithDelta(
            now()->addDays($business->periodDays)->getTimestamp(),
            $subscription->current_period_end->getTimestamp(),
            2,
        );

        $this->assertSame(1, PlanSubscription::query()->where('team_id', $team->id)->count());
    }

    public function test_a_team_with_only_an_expired_subscription_gets_a_new_active_one(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::find('pro');

        $expired = PlanSubscription::factory()->expired()->create([
            'team_id' => $team->id,
            'plan' => 'pro',
        ]);

        $subscription = resolve(ActivatePlan::class)->handle($team, $plan);

        $this->assertNotSame($expired->id, $subscription->id);
        $this->assertSame('pro', $subscription->plan);
        $this->assertTrue($subscription->isActive());
        $this->assertSame(2, PlanSubscription::query()->where('team_id', $team->id)->count());

        $this->assertSame('pro', resolve(Subscriptions::class)->active($team)?->plan);
    }
}
