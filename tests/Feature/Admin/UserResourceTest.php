<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The phase acceptance criterion is that an operator can resolve the common
 * support requests without opening the database, so the tests are written as
 * those requests.
 */
class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());
    }

    public function test_the_list_loads_and_shows_users(): void
    {
        $users = User::factory()->count(3)->create();

        $component = Livewire::test(ListUsers::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords($users);
    }

    public function test_an_operator_can_find_someone_by_name_or_email(): void
    {
        $target = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $others = User::factory()->count(3)->create();

        Livewire::test(ListUsers::class)
            ->searchTable('ada@example.com')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords($others);
    }

    /**
     * "I deleted my account by mistake." A list that hides deleted accounts
     * cannot answer that.
     */
    public function test_deleted_accounts_remain_visible(): void
    {
        $deleted = User::factory()->create();
        $deleted->delete();

        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$deleted]);
    }

    public function test_an_operator_can_resend_a_verification_email(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('resendVerification')->table($user));

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_view_page_loads(): void
    {
        $user = User::factory()->create();

        Livewire::test(ViewUser::class, ['record' => $user->getKey()])->assertOk();
    }

    /**
     * Accounts are not authored from the panel: an operator who could rewrite
     * an email could take the account over without it looking like one.
     */
    public function test_the_panel_cannot_create_or_edit_accounts(): void
    {
        $this->assertFalse(\App\Filament\Resources\Users\UserResource::canCreate());
        $this->assertArrayNotHasKey('edit', \App\Filament\Resources\Users\UserResource::getPages());
    }

    public function test_the_panel_stays_shut_for_an_ordinary_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/users')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
