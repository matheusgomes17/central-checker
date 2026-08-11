<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Billing\SwapPlan;
use App\Enums\TeamRole;
use App\Exceptions\PlanSwapException;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\Plan;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Swapping used to be impossible from inside the product: the checkout route
 * called newSubscription() without looking at whether one already existed, so
 * a subscribed team clicking another plan started a second subscription flow
 * instead of moving.
 */
class SwapPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'billing.plans.pro.price_id' => 'price_pro',
            'billing.plans.business.price_id' => 'price_business',
        ]);
    }

    public function test_it_refuses_a_team_with_no_subscription(): void
    {
        $this->expectException(PlanSwapException::class);

        resolve(SwapPlan::class)->handle(Team::factory()->create(), Plan::find('pro'));
    }

    public function test_it_refuses_the_plan_the_team_is_already_on(): void
    {
        $team = $this->subscribed('price_pro');

        $this->expectException(PlanSwapException::class);

        resolve(SwapPlan::class)->handle($team, Plan::find('pro'));
    }

    /**
     * The message names the plan, because "that did not work" with no noun is
     * the kind of refusal people open a ticket about.
     */
    public function test_the_refusal_names_the_plan(): void
    {
        $team = $this->subscribed('price_pro');

        try {
            resolve(SwapPlan::class)->handle($team, Plan::find('pro'));
            $this->fail('A troca deveria ter sido recusada.');
        } catch (PlanSwapException $e) {
            $this->assertStringContainsString('Pro', $e->getMessage());
        }
    }

    /**
     * A plan with no Stripe price is not the same failure as "no
     * subscription": the team has one, it is the destination that cannot be
     * bought. Confusing the two would send someone to fix the wrong thing.
     */
    public function test_it_refuses_a_plan_with_no_stripe_price(): void
    {
        config(['billing.plans.business.price_id' => null]);

        $team = $this->subscribed('price_pro');

        try {
            resolve(SwapPlan::class)->handle($team, Plan::find('business'));
            $this->fail('A troca deveria ter sido recusada.');
        } catch (PlanSwapException $e) {
            $this->assertStringContainsString('Business', $e->getMessage());
        }
    }

    public function test_someone_without_the_billing_permission_cannot_swap(): void
    {
        $team = $this->subscribed('price_pro');
        $member = $this->memberOf($team);

        Livewire::actingAs($member);

        Livewire::test('pages::settings.billing')
            ->call('choose', 'business')
            ->assertForbidden();
    }

    /**
     * Dropping to a plan the team no longer fits in is allowed — blocking it
     * traps whoever wants to pay less. What is not allowed is doing it in
     * silence.
     */
    public function test_dropping_below_the_current_headcount_asks_first(): void
    {
        $team = $this->subscribed('price_business');
        $owner = $this->ownerOf($team);

        foreach (range(1, 4) as $ignored) {
            $team->members()->attach(User::factory()->create());
        }

        Livewire::actingAs($owner);

        Livewire::test('pages::settings.billing')
            ->call('choose', 'free')
            ->assertSet('confirming', 'free')
            ->assertSee('5');
    }

    /**
     * Pro has no Stripe price here on purpose. Not asking for confirmation
     * still lets choose() reach SwapPlan::handle(), and a real, purchasable
     * destination would carry the call all the way to Stripe's API — the
     * network trip this suite cannot make. Leaving the destination without a
     * price stops it one line earlier, inside SwapPlan itself, without
     * touching what this test is actually about: whether confirming is asked.
     */
    public function test_dropping_within_the_headcount_does_not_ask(): void
    {
        config(['billing.plans.pro.price_id' => null]);

        $team = $this->subscribed('price_business');
        $owner = $this->ownerOf($team);

        Livewire::actingAs($owner);

        Livewire::test('pages::settings.billing')
            ->call('choose', 'pro')
            ->assertSet('confirming', null);
    }

    /**
     * The hosted checkout route is the entry point for a team with no
     * subscription yet. Reaching it while already subscribed means a stale
     * link or a typed-in URL — following through would start a second
     * subscription instead of moving the existing one.
     */
    public function test_checkout_refuses_a_team_that_already_subscribed(): void
    {
        $team = $this->subscribed('price_pro');
        $owner = $this->ownerOf($team);

        $this->actingAs($owner);

        $this->get(route('billing.checkout', ['plan' => 'business']))
            ->assertRedirect()
            ->assertSessionHasErrors('plan');
    }

    /**
     * The easiest refusal to provoke from the screen: choosing the plan the
     * team is already on. Before this fix, choose() called addError('plan',
     * ...) with no field named 'plan' anywhere in the markup — the message had
     * nowhere to render, so a refused swap looked identical to a successful
     * one. The toast is the only channel this screen has for a result the
     * person did not ask to see twice.
     */
    public function test_swapping_to_the_current_plan_shows_a_visible_refusal(): void
    {
        $team = $this->subscribed('price_pro');
        $owner = $this->ownerOf($team);

        Livewire::actingAs($owner);

        Livewire::test('pages::settings.billing')
            ->call('choose', 'pro')
            ->assertDispatched('toast-show', function (string $name, array $params): bool {
                $dataset = $params['dataset'] ?? null;
                $slots = $params['slots'] ?? null;

                if (! is_array($dataset) || ! is_array($slots)) {
                    return false;
                }

                $text = $slots['text'] ?? null;

                return ($dataset['variant'] ?? null) === 'danger'
                    && is_string($text)
                    && str_contains($text, 'Pro');
            });
    }

    private function subscribed(string $price): Team
    {
        $team = Team::factory()->create(['is_personal' => false, 'stripe_id' => 'cus_'.fake()->uuid()]);

        $team->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_'.fake()->uuid(),
            'stripe_status' => 'active',
            'stripe_price' => $price,
            'quantity' => 1,
        ]);

        return $team->refresh();
    }

    private function ownerOf(Team $team): User
    {
        return $this->memberOf($team, TeamRole::Owner);
    }

    /**
     * A team member who can reach the billing page but not change its plan.
     *
     * Admin carries ViewBilling without ManageBilling, which is what makes
     * this test exercise choose()'s own 403 instead of mount()'s: the plain
     * Member role has neither permission and would abort before choose() ever
     * ran.
     */
    private function memberOf(Team $team, TeamRole $role = TeamRole::Admin): User
    {
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, $role);
        $user->switchTeam($team);

        return $user;
    }
}
