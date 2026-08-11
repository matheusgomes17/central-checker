<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Enums\SystemRole;
use App\Models\User;
use App\Providers\TelescopeServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The metrics dashboards show every customer at once. Who may open them is the
 * same question as who may open the operator panel, and it should have one
 * answer rather than one per package.
 */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulse_is_closed_to_an_ordinary_user(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewPulse'));
    }

    public function test_pulse_opens_for_a_super_admin(): void
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        $this->assertTrue(Gate::forUser($user)->allows('viewPulse'));
    }

    /**
     * A guest must not be able to reach it either. The gate takes a nullable
     * user precisely so this case is answered instead of raising.
     */
    public function test_pulse_is_closed_to_a_guest(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('viewPulse'));
    }

    /**
     * Telescope records everything — request bodies, queries, emails — which
     * is useful on a developer's machine and a leak anywhere else.
     *
     * What keeps it out is not a gate. The application's Gate::before grants a
     * super admin every ability, so no definition of viewTelescope could
     * refuse one. The protection is that the provider is only registered in
     * local, and this asserts that rather than the gate.
     */
    public function test_telescope_is_not_registered_outside_local(): void
    {
        $this->assertNotSame('local', $this->app->environment());

        $this->assertArrayNotHasKey(
            TelescopeServiceProvider::class,
            $this->app->getLoadedProviders(),
        );
    }

    /**
     * The package is a dev dependency, so a production install does not even
     * have the class. Listing it in bootstrap/providers.php would break boot.
     */
    public function test_telescope_is_not_listed_among_the_bootstrapped_providers(): void
    {
        /** @var array<int, class-string> $providers */
        $providers = require base_path('bootstrap/providers.php');

        $this->assertNotContains(TelescopeServiceProvider::class, $providers);
    }

    public function test_the_three_operator_dashboards_share_one_rule(): void
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        foreach (['viewPulse', 'viewHorizon', 'viewApiDocs'] as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability), "super admin recusado em {$ability}");
        }

        $ordinary = User::factory()->create();

        foreach (['viewPulse', 'viewHorizon', 'viewApiDocs'] as $ability) {
            $this->assertFalse(Gate::forUser($ordinary)->allows($ability), "usuário comum aceito em {$ability}");
        }
    }
}
