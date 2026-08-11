<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Widgets\RevenueOverview;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\SubscriptionStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->setSystemRole(SystemRole::SuperAdmin);
        $admin->refresh();

        $this->actingAs($admin);
    }

    public function test_the_subscriptions_list_loads(): void
    {
        $subscription = PlanSubscription::factory()->active()->for(Team::factory())->create();

        $component = Livewire::test(ListSubscriptions::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords([$subscription]);
    }

    /**
     * A row still inside its paid period but marked not to renew shows as
     * `Expiring`, not `Active` — the panel needs to say "this one is about to
     * lapse", not just "this one currently has access".
     */
    public function test_a_canceling_row_shows_the_expiring_state(): void
    {
        PlanSubscription::factory()->canceling()->for(Team::factory())->create();

        Livewire::test(ListSubscriptions::class)
            ->assertOk()
            ->assertSee(SubscriptionStatus::Expiring->label());
    }

    /**
     * The payment webhook owns these rows; the panel keeps a read-only copy.
     * Editing it here would put the panel and the source out of step, and the
     * next webhook would silently undo whatever was typed.
     */
    public function test_subscriptions_cannot_be_edited_from_the_panel(): void
    {
        $this->assertFalse(SubscriptionResource::canCreate());
        $this->assertSame(['index'], array_keys(SubscriptionResource::getPages()));
    }

    public function test_the_revenue_widget_sums_only_active_subscriptions(): void
    {
        PlanSubscription::factory()->active()->for(Team::factory())->create(['plan' => 'pro']); // 49,00
        PlanSubscription::factory()->expired()->for(Team::factory())->create(['plan' => 'business']); // não conta

        Livewire::test(RevenueOverview::class)->assertOk()->assertSee('49,00');
    }

    /**
     * Money that has not been charged is not revenue, and counting it is how a
     * forecast turns into a fiction.
     */
    public function test_a_trial_is_reported_separately_from_revenue(): void
    {
        Team::factory()->create(['trial_ends_at' => now()->addDays(7)]);

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
}
