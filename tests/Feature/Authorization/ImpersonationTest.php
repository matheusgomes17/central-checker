<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\AuditEvent;
use App\Enums\SystemRole;
use App\Models\User;
use App\Support\Impersonation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_super_admin_can_act_as_another_user(): void
    {
        [$admin, $target] = [$this->superAdmin(), User::factory()->create()];

        $this->actingAs($admin)
            ->post(route('impersonate.start', $target))
            ->assertRedirect();

        $this->assertAuthenticatedAs($target);
        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));
    }

    public function test_an_ordinary_user_cannot_impersonate(): void
    {
        [$user, $target] = [User::factory()->create(), User::factory()->create()];

        $this->actingAs($user)
            ->post(route('impersonate.start', $target))
            ->assertSessionHasErrors('impersonation');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Impersonating another administrator would be one privileged person
     * acting under the identity of another — the exact thing the trail
     * exists to make impossible to hide.
     */
    public function test_a_platform_administrator_cannot_be_impersonated(): void
    {
        [$admin, $target] = [$this->superAdmin(), $this->superAdmin()];

        $this->actingAs($admin)
            ->post(route('impersonate.start', $target))
            ->assertSessionHasErrors('impersonation');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_stopping_returns_to_the_administrator(): void
    {
        [$admin, $target] = [$this->superAdmin(), User::factory()->create()];

        $this->actingAs($admin)->post(route('impersonate.start', $target));

        $this->delete(route('impersonate.stop'))->assertRedirect();

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(Impersonation::SESSION_KEY));
    }

    /**
     * Both ends are written down. A start with no stop, or a stop with no
     * start, is what an audit reader needs to be able to notice.
     */
    public function test_both_ends_are_recorded_in_the_audit_trail(): void
    {
        [$admin, $target] = [$this->superAdmin(), User::factory()->create()];

        $this->actingAs($admin)->post(route('impersonate.start', $target));
        $this->delete(route('impersonate.stop'));

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'audit',
            'event' => AuditEvent::ImpersonationStarted->value,
            'subject_id' => $target->id,
            'causer_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'audit',
            'event' => AuditEvent::ImpersonationStopped->value,
            'subject_id' => $target->id,
            'causer_id' => $admin->id,
        ]);
    }

    /**
     * The banner is the other half of not being silent. Someone whose account
     * was accessed has to be able to see it in a screenshot.
     */
    public function test_the_banner_shows_while_impersonating(): void
    {
        [$admin, $target] = [$this->superAdmin(), User::factory()->create()];

        $this->actingAs($admin)->post(route('impersonate.start', $target));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="impersonation-banner"', false)
            ->assertSee($admin->name);
    }

    public function test_the_banner_is_absent_in_an_ordinary_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-test="impersonation-banner"', false);
    }

    public function test_it_refuses_to_nest(): void
    {
        [$admin, $first, $second] = [$this->superAdmin(), User::factory()->create(), User::factory()->create()];

        $this->actingAs($admin)->post(route('impersonate.start', $first));

        $this->post(route('impersonate.start', $second))
            ->assertSessionHasErrors('impersonation');

        $this->assertAuthenticatedAs($first);
    }

    public function test_stopping_without_impersonating_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('impersonate.stop'))
            ->assertSessionHasErrors('impersonation');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);

        $user->refresh();

        return $user;
    }
}
