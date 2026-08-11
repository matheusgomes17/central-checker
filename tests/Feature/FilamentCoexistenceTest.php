<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 0 spike: the plan flags "Filament 5 alongside Livewire 4" as a medium
 * risk to validate before building on top of it.
 *
 * The concrete risk is the two stacks stepping on each other — competing for
 * the Livewire update endpoint, or Filament taking over the application's own
 * component namespace. These tests prove both surfaces answer side by side.
 */
class FilamentCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_filament_panel_is_registered_on_its_own_path(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('admin', $panel->getId());
        $this->assertSame('admin', $panel->getPath());
    }

    public function test_the_filament_login_screen_renders(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_the_filament_panel_rejects_guests(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    /**
     * The panel is now closed by an access rule — canAccessPanel() requires
     * super admin — rather than by Filament's environment fallback. This test
     * stays as the alarm for the production case; SuperAdminTest covers the
     * rule itself, including that being local no longer opens the door.
     */
    public function test_the_panel_is_closed_outside_local_environments(): void
    {
        $user = User::factory()->create();

        config(['app.env' => 'production']);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_the_application_livewire_surface_still_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk();
    }

    /**
     * Livewire 4 tolerates more than one update route as long as the name ends
     * in "livewire.update", and prefers a named one over the default. Had
     * Filament registered its own, component requests from the application
     * could land in the panel instead. There is exactly one, and it is the
     * default route registered by Livewire.
     */
    public function test_filament_does_not_register_a_competing_livewire_update_route(): void
    {
        $updateRoutes = array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route): bool => str_ends_with((string) $route->getName(), 'livewire.update'),
        );

        $this->assertCount(1, $updateRoutes);
        $this->assertSame('default-livewire.update', reset($updateRoutes)->getName());
    }

    public function test_filament_does_not_hijack_the_application_component_namespace(): void
    {
        $this->assertTrue(Livewire::isDiscoverable('pages::settings.profile'));
    }
}
