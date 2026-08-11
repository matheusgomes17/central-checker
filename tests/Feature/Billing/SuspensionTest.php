<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TeamBilling;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The end of the line the plan describes: payment fails, Stripe retries, the
 * retries run out, and what is left is an account that can be read and paid
 * for but not added to.
 */
class SuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['billing.plans.pro.price_id' => 'price_pro']);

        Route::middleware(['web', 'auth', 'not-suspended'])->group(function (): void {
            Route::get('probe', fn (): string => 'read')->name('probe.read');
            Route::post('probe', fn (): string => 'write')->name('probe.write');

            // A liberação é por prefixo de nome, e o nome precisa ser mais
            // longo que o prefixo para o teste distinguir "começa com" de
            // "termina com" — as rotas reais de cobrança são assim.
            Route::post('probe-billing', fn (): string => 'pagar')->name('billing.probe');

            // Rota sem nome: não há como estar na lista de liberadas.
            Route::post('probe-anonymous', fn (): string => 'anônima');
        });
    }

    public function test_a_team_that_never_subscribed_is_not_suspended(): void
    {
        $team = Team::factory()->create();

        $this->assertFalse(TeamBilling::for($team)->isReadOnly());
    }

    public function test_a_team_still_in_its_grace_period_is_not_suspended(): void
    {
        $team = $this->subscribed('active', endsAt: now()->addDays(5));

        $this->assertSame(SubscriptionStatus::Cancelling, TeamBilling::for($team)->status());
        $this->assertFalse(TeamBilling::for($team)->isReadOnly());
    }

    public function test_a_team_with_a_failing_payment_is_not_suspended(): void
    {
        $team = $this->subscribed('past_due');

        $this->assertFalse(TeamBilling::for($team)->isReadOnly());
    }

    public function test_a_team_whose_subscription_ended_is_suspended(): void
    {
        $team = $this->subscribed('canceled', endsAt: now()->subDay());

        $this->assertTrue(TeamBilling::for($team)->isReadOnly());
    }

    /**
     * Reading still works: the point of read-only is that nothing is lost
     * while the bill goes unpaid.
     */
    public function test_a_suspended_team_can_still_read(): void
    {
        $this->actingAsMemberOf($this->subscribed('canceled', endsAt: now()->subDay()));

        $this->get('probe')->assertOk();
    }

    public function test_a_suspended_team_cannot_write(): void
    {
        $this->actingAsMemberOf($this->subscribed('canceled', endsAt: now()->subDay()));

        $this->post('probe')->assertStatus(402);
    }

    public function test_a_team_in_good_standing_can_write(): void
    {
        $this->actingAsMemberOf($this->subscribed('active'));

        $this->post('probe')->assertOk();
    }

    /**
     * A suspension that blocks the page where you would pay is a suspension
     * nobody can lift.
     */
    public function test_a_suspended_team_can_still_reach_billing(): void
    {
        $team = $this->subscribed('canceled', endsAt: now()->subDay());

        $user = $this->actingAsMemberOf($team, TeamRole::Owner);

        $this->assertTrue($user->hasTeamPermission($team, \App\Enums\TeamPermission::ManageBilling));

        $this->get(route('billing.edit'))->assertOk();
    }

    /**
     * Reading the billing page is not enough — paying is a write, and a write
     * is exactly what the suspension blocks. The exception has to survive the
     * method check or the page is a dead end with a button on it.
     */
    public function test_a_suspended_team_can_still_write_to_a_billing_route(): void
    {
        $this->actingAsMemberOf($this->subscribed('canceled', endsAt: now()->subDay()));

        $this->post('probe-billing')->assertOk();
    }

    /**
     * A route with no name cannot be on a list keyed by name, and guessing in
     * its favor would open every unnamed write route to a suspended team.
     */
    public function test_a_write_to_an_unnamed_route_is_still_refused(): void
    {
        $this->actingAsMemberOf($this->subscribed('canceled', endsAt: now()->subDay()));

        $this->post('probe-anonymous')->assertStatus(402);
    }

    public function test_the_middleware_lets_a_request_without_a_team_through(): void
    {
        // Usuário recém-criado tem time pessoal, e time pessoal nunca assinou:
        // o middleware precisa deixar passar em vez de tratar como suspenso.
        $this->actingAs(User::factory()->create());

        $this->post('probe')->assertOk();
    }

    private function actingAsMemberOf(Team $team, TeamRole $role = TeamRole::Owner): User
    {
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, $role);
        $user->switchTeam($team);

        $this->actingAs($user);

        return $user;
    }

    private function subscribed(string $status, ?CarbonInterface $endsAt = null): Team
    {
        $team = Team::factory()->create([
            'is_personal' => false,
            'stripe_id' => 'cus_'.fake()->uuid(),
        ]);

        $team->subscriptions()->create([
            'type' => TeamBilling::SUBSCRIPTION,
            'stripe_id' => 'sub_'.fake()->uuid(),
            'stripe_status' => $status,
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]);

        return $team->refresh();
    }
}
