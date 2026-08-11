<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Widgets\RevenueOverview;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Livewire\Livewire;
use Tests\TestCase;

class BillingPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'billing.plans.pro.price_id' => 'price_pro',
            'billing.plans.business.price_id' => 'price_business',
            // Fixado aqui para que a asserção do formato não dependa do
            // ambiente de quem roda a suíte.
            'cashier.currency' => 'brl',
            'cashier.currency_locale' => 'pt_BR',
        ]);

        $admin = User::factory()->create();
        $admin->setSystemRole(SystemRole::SuperAdmin);
        $admin->refresh();

        $this->actingAs($admin);
    }

    public function test_the_subscriptions_list_loads(): void
    {
        $subscription = $this->subscription('price_pro', 'active');

        $component = Livewire::test(ListSubscriptions::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords([$subscription]);
    }

    /**
     * Stripe owns these rows; the panel keeps a copy that webhooks maintain.
     * Editing it here would put the copy and the source out of step, and the
     * next webhook would silently undo whatever was typed.
     */
    public function test_subscriptions_cannot_be_edited_from_the_panel(): void
    {
        $this->assertFalse(SubscriptionResource::canCreate());
        $this->assertSame(['index'], array_keys(SubscriptionResource::getPages()));
    }

    public function test_the_revenue_widget_sums_only_what_is_being_charged(): void
    {
        $this->subscription('price_pro', 'active');          // 49,00
        $this->subscription('price_business', 'active');     // 199,00
        $this->subscription('price_pro', 'trialing');        // não conta
        $this->subscription('price_pro', 'canceled', endsAt: now()->subDay()); // não conta

        Livewire::test(RevenueOverview::class)->assertOk()->assertSee('248,00');
    }

    /**
     * Money that has not been charged is not revenue, and counting it is how a
     * forecast turns into a fiction.
     */
    public function test_a_trial_is_reported_separately_from_revenue(): void
    {
        $this->subscription('price_pro', 'trialing');

        // Sem o símbolo: o ICU insere espaço não separável entre a moeda e o
        // número, que sai escapado no HTML e nunca casaria literalmente.
        Livewire::test(RevenueOverview::class)
            ->assertOk()
            ->assertSee('0,00');
    }

    /**
     * A churn rate computed against an empty base is a division by zero
     * dressed up as a percentage.
     */
    public function test_churn_is_zero_when_there_is_nothing_to_lose(): void
    {
        Livewire::test(RevenueOverview::class)->assertOk()->assertSee('0%');
    }

    private function subscription(string $price, string $status, ?\Carbon\CarbonInterface $endsAt = null): Subscription
    {
        $team = Team::factory()->create([
            'is_personal' => false,
            'stripe_id' => 'cus_'.fake()->uuid(),
        ]);

        /** @var Subscription $subscription */
        $subscription = $team->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_'.fake()->uuid(),
            'stripe_status' => $status,
            'stripe_price' => $price,
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]);

        return $subscription;
    }
}
