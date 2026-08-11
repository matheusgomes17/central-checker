<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_account_is_a_soft_delete(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertGuest();
    }

    /**
     * The whole point of ending access immediately. The user provider queries
     * through the model, so the soft delete scope is what keeps a deleted
     * account out — a regression here silently reopens every cancelled
     * account.
     */
    public function test_a_deleted_account_cannot_authenticate(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_a_deleted_account_cannot_request_a_password_reset(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->post(route('password.request'), ['email' => $user->email])
            ->assertSessionHasErrors('email');
    }

    public function test_the_purge_leaves_accounts_inside_the_retention_window_alone(): void
    {
        config(['accounts.purge_after_days' => 30]);

        $user = User::factory()->create();
        $user->delete();

        $user->forceFill(['deleted_at' => now()->subDays(29)])->saveQuietly();

        $this->artisan('users:purge')->assertSuccessful();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_the_purge_removes_accounts_past_the_retention_window(): void
    {
        config(['accounts.purge_after_days' => 30]);

        $user = User::factory()->create();
        $user->delete();

        $user->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

        $this->artisan('users:purge')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_the_purge_never_touches_live_accounts(): void
    {
        config(['accounts.purge_after_days' => 0]);

        $user = User::factory()->create();

        $this->artisan('users:purge')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_the_dry_run_removes_nothing(): void
    {
        config(['accounts.purge_after_days' => 30]);

        $user = User::factory()->create();
        $user->delete();

        $user->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

        $this->artisan('users:purge', ['--dry-run' => true])->assertSuccessful();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_the_purge_is_scheduled(): void
    {
        $scheduled = collect($this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($event): bool => str_contains((string) $event->command, 'users:purge'));

        $this->assertTrue($scheduled, 'O comando users:purge precisa estar agendado.');
    }
}
