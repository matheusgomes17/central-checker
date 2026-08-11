<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Pagamentos\Enums\PaymentPurpose;
use Modules\Pagamentos\Models\PaymentIntent;
use Tests\TestCase;

/**
 * The billing page reads payment history from our own `PaymentIntent` table
 * now — there is no Stripe invoice to fall back to in the prepaid model, and
 * no live call that can fail on its own the way the old Stripe read could.
 */
class PaymentsHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_team_with_no_payments_sees_an_empty_state(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $owner = $this->ownerOf($team);

        $this->actingAs($owner)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertSee(__('No payments yet'));
    }

    public function test_the_payments_section_shows_the_teams_plan_intents(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $owner = $this->ownerOf($team);

        $paid = PaymentIntent::factory()->for($team)->paid()->create([
            'purpose' => PaymentPurpose::Plan,
            'plan' => 'pro',
            'amount_cents' => 9900,
        ]);

        $pending = PaymentIntent::factory()->for($team)->create([
            'purpose' => PaymentPurpose::Plan,
            'plan' => 'pro',
            'amount_cents' => 9900,
        ]);

        $this->actingAs($owner);

        $component = Livewire::test('pages::settings.billing');

        $payments = $component->instance()->payments();

        $this->assertTrue($payments->contains('id', $paid->id));
        $this->assertTrue($payments->contains('id', $pending->id));

        $component
            ->assertSee(__('Paid'))
            ->assertSee(__('Pending'));
    }

    public function test_a_recharge_intent_does_not_appear_among_plan_payments(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $owner = $this->ownerOf($team);

        $recharge = PaymentIntent::factory()->for($team)->paid()->create([
            'purpose' => PaymentPurpose::Recharge,
        ]);

        $this->actingAs($owner);

        $payments = Livewire::test('pages::settings.billing')->instance()->payments();

        $this->assertFalse($payments->contains('id', $recharge->id));
    }

    private function ownerOf(Team $team): User
    {
        return $this->memberOf($team, TeamRole::Owner);
    }

    private function memberOf(Team $team, TeamRole $role = TeamRole::Member): User
    {
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, $role);
        $user->switchTeam($team);

        return $user;
    }
}
