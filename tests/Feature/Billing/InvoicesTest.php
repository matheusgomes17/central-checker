<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screen has promised "Plan, usage and invoices for this team" since the
 * billing phase and listed none.
 *
 * Reading is live from Stripe, so the happy path is not testable offline. What
 * is testable — and is the part that would leak — is that one team cannot reach
 * another team's invoice.
 */
class InvoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_team_with_no_billing_account_sees_an_empty_state(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $owner = $this->ownerOf($team);

        $this->actingAs($owner)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertSee(__('No invoices yet'));
    }

    /**
     * The section reads live from Stripe, and the offline suite's sentinel
     * key (`sk_test_deliberately_invalid`) makes every real call to Stripe
     * fail on authentication — which doubles as a stand-in for "Stripe is
     * down" that this suite can reach without a network. A page that shows
     * a plan and usage but 500s the moment invoices fails to load would be
     * worse than the empty screen this task set out to fix.
     */
    public function test_the_invoices_section_fails_on_its_own_when_stripe_is_unreachable(): void
    {
        $team = Team::factory()->create(['is_personal' => false, 'stripe_id' => 'cus_unreachable']);
        $owner = $this->ownerOf($team);

        $this->actingAs($owner)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertSee(__('No invoices yet'));
    }

    /**
     * Cashier throws InvalidInvoice when the invoice belongs to someone else,
     * and only findInvoiceOrFail() turns that into a 403 — the plain
     * findInvoice() does not, and using it here would hand one team another
     * team's invoice.
     *
     * That path cannot be exercised offline: the sentinel key phpunit.xml sets
     * for the offline suite (`sk_test_deliberately_invalid`) makes the Stripe
     * call fail on authentication before Cashier's owner check ever runs — a
     * request against `route('billing.invoice', ...)` here would get a 500
     * from Stripe\Exception\AuthenticationException, not the 403 this is
     * actually about. Catching that exception to fake a 403 would turn "Stripe
     * is down" into "access denied", which is worse than not testing this at
     * all. So this asserts the one thing that can be checked without a network
     * call: that the controller reaches the invoice through the method that
     * performs the owner check. The live cross-team case belongs in
     * StripeIntegrationTest, which runs with a real key.
     */
    public function test_the_controller_downloads_through_the_owner_checking_method(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/BillingController.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('->downloadInvoice(', $source);
        $this->assertStringNotContainsString('->findInvoice(', $source);
    }

    public function test_someone_without_the_billing_permission_cannot_download(): void
    {
        $team = Team::factory()->create(['is_personal' => false, 'stripe_id' => 'cus_ours']);
        $member = $this->memberOf($team);

        $this->actingAs($member)
            ->get(route('billing.invoice', ['invoice' => 'in_whatever']))
            ->assertForbidden();
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
