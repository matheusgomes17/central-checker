<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\PlanSubscription;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\Plan;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TeamBilling;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * TeamBilling answers a handful of overlapping true/false questions, and
 * reading them at each call site is how two screens end up disagreeing about
 * one account. These tests pin down the single reading of them, state by
 * state, against the in-house `PlanSubscription` row — no Stripe involved.
 */
class TeamBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.plans.pro.price_id' => 'price_pro',
            'billing.plans.business.price_id' => 'price_business',
        ]);
    }

    public function test_a_team_with_no_subscription_is_on_the_default_plan(): void
    {
        $billing = TeamBilling::for(Team::factory()->create());

        $this->assertSame(Plan::default()->key, $billing->plan()->key);
        $this->assertSame(SubscriptionStatus::None, $billing->status());
        $this->assertFalse($billing->hasAccess());
    }

    /**
     * Not paying is a state of the product, not the absence of one: the free
     * plan carries real limits.
     */
    public function test_the_default_plan_still_imposes_limits(): void
    {
        $billing = TeamBilling::for(Team::factory()->create());

        $this->assertSame(3, $billing->plan()->limit('members'));
    }

    public function test_an_active_subscription_puts_the_team_on_its_plan(): void
    {
        $billing = TeamBilling::for($this->subscribed('pro'));

        $this->assertSame('pro', $billing->plan()->key);
        $this->assertSame(SubscriptionStatus::Active, $billing->status());
        $this->assertTrue($billing->hasAccess());
    }

    /**
     * A card-free trial has no subscription row at all, so trial state has to
     * be read from the team.
     */
    public function test_a_card_free_trial_grants_access(): void
    {
        // trial_ends_at não é fillable no Team: quem preenche é o Cashier.
        $team = Team::factory()->create();
        $team->forceFill(['trial_ends_at' => now()->addDays(14)])->save();

        $billing = TeamBilling::for($team);

        $this->assertSame(SubscriptionStatus::Trialing, $billing->status());
        $this->assertTrue($billing->hasAccess());

        // Teste sem cartão não tem assinatura para consultar. Perguntar o
        // plano aqui é o único caminho que passa por um time com acesso e
        // nenhuma linha de assinatura.
        $this->assertSame(Plan::default()->key, $billing->plan()->key);
    }

    public function test_an_expired_trial_grants_nothing(): void
    {
        $team = Team::factory()->create();
        $team->forceFill(['trial_ends_at' => now()->subDay()])->save();

        $billing = TeamBilling::for($team);

        $this->assertSame(SubscriptionStatus::None, $billing->status());
        $this->assertFalse($billing->hasAccess());
    }

    /**
     * Cancelling does not end the period already paid for: a subscription
     * marked not to renew still counts as subscribed until the period ends.
     */
    public function test_a_cancelled_subscription_keeps_access_until_the_period_ends(): void
    {
        $team = $this->subscribed('pro', canceling: true, endsAt: now()->addDays(10));

        $billing = TeamBilling::for($team);

        $this->assertTrue($billing->subscribed());
        $this->assertTrue($billing->onGracePeriod());
        $this->assertSame(SubscriptionStatus::Expiring, $billing->status());
        $this->assertSame('pro', $billing->plan()->key);
    }

    public function test_a_subscription_past_its_end_grants_nothing(): void
    {
        $team = Team::factory()->create();
        PlanSubscription::factory()->expired()->create(['team_id' => $team->id, 'plan' => 'pro']);

        $billing = TeamBilling::for($team);

        $this->assertSame(SubscriptionStatus::Expired, $billing->status());
        $this->assertFalse($billing->hasAccess());
        $this->assertSame(Plan::default()->key, $billing->plan()->key);
    }

    public function test_usage_reports_the_limit_and_what_is_left(): void
    {
        $team = Team::factory()->create();

        $team->memberships()->create(['user_id' => User::factory()->create()->id]);

        $usage = TeamBilling::for($team)->usage('members');

        $this->assertSame(3, $usage['limit']);
        $this->assertSame(1, $usage['used']);
        $this->assertSame(2, $usage['remaining']);
    }

    /**
     * Null and zero are different answers: no ceiling versus none allowed.
     */
    public function test_a_plan_without_a_ceiling_reports_null_rather_than_zero(): void
    {
        $team = $this->subscribed('business');

        $usage = TeamBilling::for($team)->usage('members');

        $this->assertNull($usage['limit']);
        $this->assertNull($usage['remaining']);
        $this->assertTrue(TeamBilling::for($team)->withinLimit('members', 10_000));
    }

    public function test_the_limit_refuses_the_addition_that_would_cross_it(): void
    {
        $team = Team::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $team->memberships()->create(['user_id' => User::factory()->create()->id]);
        }

        $billing = TeamBilling::for($team);

        $this->assertFalse($billing->withinLimit('members'));
        $this->assertSame(0, $billing->usage('members')['remaining']);
    }

    /**
     * The limit is a ceiling, not a wall one short of it. Refusing the member
     * that lands exactly on the number sells three seats and delivers two.
     */
    public function test_the_limit_admits_the_addition_that_lands_exactly_on_it(): void
    {
        $team = Team::factory()->create();

        foreach (range(1, 2) as $ignored) {
            $team->memberships()->create(['user_id' => User::factory()->create()->id]);
        }

        $this->assertTrue(TeamBilling::for($team)->withinLimit('members'));
    }

    /**
     * A team can end up over its ceiling — the plan changes, the limit drops,
     * and the members already there stay. What is left to spend is zero, and
     * a negative number would read as credit.
     */
    public function test_usage_never_reports_a_negative_remainder(): void
    {
        $team = Team::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $team->memberships()->create(['user_id' => User::factory()->create()->id]);
        }

        $usage = TeamBilling::for($team)->usage('members');

        $this->assertSame(3, $usage['limit']);
        $this->assertSame(5, $usage['used']);
        $this->assertSame(0, $usage['remaining']);
    }

    /**
     * Members is the only resource the boilerplate meters. Asking about
     * anything else used to answer zero; it now refuses, because zero read as
     * "nothing consumed" when it actually meant "nobody is counting".
     */
    public function test_a_resource_with_no_counter_refuses_rather_than_reporting_no_use(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TeamBilling::for(Team::factory()->create())->usage('projects');
    }

    /**
     * The one lookup that runs the other way, from Stripe's identifier back to
     * ours. It exists for webhooks, which speak only Stripe — and a webhook
     * that resolves the wrong plan moves a paying team to the free one.
     */
    public function test_a_price_that_belongs_to_no_plan_resolves_to_nothing(): void
    {
        $this->assertSame('pro', Plan::forPriceId('price_pro')?->key);

        // O plano gratuito não tem price id. Sem a saída antecipada, procurar
        // por "nenhum preço" acharia justamente ele.
        $this->assertNull(Plan::forPriceId(null));
        $this->assertNull(Plan::forPriceId(''));
        $this->assertNull(Plan::forPriceId('price_que_nao_existe'));
    }

    /**
     * A plan with no Stripe price cannot be bought, whatever the config says.
     */
    public function test_only_plans_with_a_price_are_purchasable(): void
    {
        $this->assertSame(['pro', 'business'], array_keys(Plan::purchasable()));
    }

    /**
     * A declared but empty environment variable arrives as an empty string,
     * not as null. Left alone, the plan would be offered and would only fail
     * at Stripe, with an error that means nothing to whoever clicked.
     */
    public function test_a_plan_whose_price_is_an_empty_string_is_not_purchasable(): void
    {
        config(['billing.plans.pro.price_id' => '']);

        $this->assertSame(['business'], array_keys(Plan::purchasable()));
        $this->assertTrue(Plan::find('pro')->isFree());
    }

    private function subscribed(string $plan = 'pro', bool $canceling = false, ?CarbonInterface $endsAt = null): Team
    {
        $team = Team::factory()->create();

        PlanSubscription::factory()->create([
            'team_id' => $team->id,
            'plan' => $plan,
            'current_period_start' => now()->subDay(),
            'current_period_end' => $endsAt ?? now()->addDays(30),
            'canceled_at' => $canceling ? now() : null,
        ]);

        return $team;
    }
}
