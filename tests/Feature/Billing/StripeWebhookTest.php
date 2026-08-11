<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Billing\PaymentFailed;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The acceptance criterion for this phase, exercised with payloads rather than
 * with Stripe.
 *
 * Webhooks are the only way the application ever learns that a subscription
 * changed, so the interesting cases are the ones nobody triggers by hand: a
 * charge that failed, a subscription cancelled from the Stripe dashboard, a
 * plan swapped outside the product.
 *
 * No signing secret is configured in tests, which is what leaves the signature
 * middleware off. Production sets STRIPE_WEBHOOK_SECRET and the same route
 * refuses anything unsigned.
 */
class StripeWebhookTest extends TestCase
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

    public function test_a_failed_charge_notifies_the_people_who_can_fix_it(): void
    {
        Notification::fake();

        [$team, $owner, $member] = $this->team();

        $this->postWebhook('invoice.payment_failed', [
            'customer' => $team->stripe_id,
            'amount_due' => 4900,
            'attempt_count' => 2,
        ])->assertOk();

        Notification::assertSentTo($owner, PaymentFailed::class);

        // Um membro comum não gere cobrança: contar a ele o estado da conta
        // seria vazar o que ele não pode resolver.
        Notification::assertNotSentTo($member, PaymentFailed::class);
    }

    public function test_the_notification_carries_the_attempt_and_the_amount(): void
    {
        Notification::fake();

        [$team, $owner] = $this->team();

        $this->postWebhook('invoice.payment_failed', [
            'customer' => $team->stripe_id,
            'amount_due' => 12_300,
            'attempt_count' => 3,
        ]);

        Notification::assertSentTo($owner, PaymentFailed::class, fn (PaymentFailed $notification): bool => $notification->amountDue === 12_300 && $notification->attempt === 3);
    }

    public function test_a_failed_charge_for_an_unknown_customer_is_accepted_and_ignored(): void
    {
        Notification::fake();

        $this->postWebhook('invoice.payment_failed', [
            'customer' => 'cus_does_not_exist',
            'amount_due' => 100,
            'attempt_count' => 1,
        ])->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * Cancelling from the Stripe dashboard has to reach the product, or the
     * team keeps access it no longer pays for.
     */
    public function test_a_subscription_deleted_in_stripe_ends_access(): void
    {
        [$team] = $this->team();

        /** @var \Laravel\Cashier\Subscription $subscription */
        $subscription = $team->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_test',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        $this->assertTrue(TeamBilling::for($team->refresh())->hasAccess());

        $this->postWebhook('customer.subscription.deleted', [
            'id' => $subscription->stripe_id,
            'customer' => $team->stripe_id,
        ])->assertOk();

        $billing = TeamBilling::for($team->refresh());

        $this->assertFalse($billing->hasAccess());
        $this->assertSame('free', $billing->plan()->key);
    }

    /**
     * A plan swapped outside the product still has to move the team.
     */
    public function test_a_subscription_updated_in_stripe_moves_the_plan(): void
    {
        [$team] = $this->team();

        $team->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_test',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        $this->postWebhook('customer.subscription.updated', [
            'id' => 'sub_test',
            'customer' => $team->stripe_id,
            'status' => 'active',
            'quantity' => 1,
            'items' => [
                'data' => [[
                    'id' => 'si_test',
                    'price' => ['id' => 'price_business', 'product' => 'prod_test'],
                    'quantity' => 1,
                ]],
            ],
        ])->assertOk();

        $this->assertSame('business', TeamBilling::for($team->refresh())->plan()->key);
    }

    /**
     * Stripe marking a subscription past due must not read as cancelled: the
     * way out is a new card, and access continues while it retries.
     */
    public function test_a_past_due_status_keeps_access_and_flags_the_failure(): void
    {
        [$team] = $this->team();

        $team->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_test',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        $this->postWebhook('customer.subscription.updated', [
            'id' => 'sub_test',
            'customer' => $team->stripe_id,
            'status' => 'past_due',
            'quantity' => 1,
            'items' => [
                'data' => [[
                    'id' => 'si_test',
                    'price' => ['id' => 'price_pro', 'product' => 'prod_test'],
                    'quantity' => 1,
                ]],
            ],
        ])->assertOk();

        $billing = TeamBilling::for($team->refresh());

        $this->assertTrue($billing->paymentFailing());
        $this->assertTrue($billing->hasAccess());
    }

    /**
     * @param  array<string, mixed>  $object
     * @return TestResponse<Response>
     */
    private function postWebhook(string $type, array $object): TestResponse
    {
        return $this->postJson(route('cashier.webhook'), [
            'id' => 'evt_'.fake()->uuid(),
            'type' => $type,
            'data' => ['object' => $object],
        ]);
    }

    /**
     * @return array{Team, User, User}
     */
    private function team(): array
    {
        $team = Team::factory()->create([
            'is_personal' => false,
            'stripe_id' => 'cus_'.fake()->uuid(),
        ]);

        $owner = User::factory()->create();
        $member = User::factory()->create();

        $team->memberships()->create(['user_id' => $owner->id]);
        $team->memberships()->create(['user_id' => $member->id]);

        $owner->assignTeamRole($team, TeamRole::Owner);
        $member->assignTeamRole($team, TeamRole::Member);

        return [$team, $owner, $member];
    }
}
