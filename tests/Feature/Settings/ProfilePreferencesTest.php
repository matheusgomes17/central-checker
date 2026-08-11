<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfilePreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['accounts.avatar_disk' => 'avatars']);

        Storage::fake('avatars');
    }

    public function test_a_user_can_upload_an_avatar(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->image('me.jpg', 200, 200))
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk('avatars')->assertExists($user->avatar_path);
    }

    /**
     * Replacing an avatar has to clean up after itself, otherwise every
     * upload leaves a orphan file in the bucket forever.
     */
    public function test_replacing_an_avatar_deletes_the_previous_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->image('first.jpg', 200, 200))
            ->call('updateProfileInformation');

        $first = $user->refresh()->avatar_path;

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->image('second.jpg', 200, 200))
            ->call('updateProfileInformation');

        $second = $user->refresh()->avatar_path;

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        Storage::disk('avatars')->assertMissing($first);
        Storage::disk('avatars')->assertExists($second);
    }

    public function test_a_user_can_remove_their_avatar(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->image('me.jpg', 200, 200))
            ->call('updateProfileInformation');

        $path = $user->refresh()->avatar_path;

        $this->assertNotNull($path);

        Livewire::test('pages::settings.profile')->call('deleteAvatar');

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('avatars')->assertMissing($path);
    }

    public function test_it_rejects_files_that_are_not_images(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->create('payload.php', 16, 'application/x-httpd-php'))
            ->call('updateProfileInformation')
            ->assertHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_a_user_can_choose_a_language_and_a_timezone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('locale', 'en')
            ->set('timezone', 'America/Sao_Paulo')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('en', $user->locale);
        $this->assertSame('America/Sao_Paulo', $user->timezone);
    }

    /**
     * Blank means "follow the application default". Storing the default value
     * instead of null would freeze the user on today's default forever.
     */
    public function test_clearing_the_preferences_stores_null(): void
    {
        $user = User::factory()->create(['locale' => 'en', 'timezone' => 'America/Sao_Paulo']);

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('locale', '')
            ->set('timezone', '')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertNull($user->locale);
        $this->assertNull($user->timezone);
    }

    public function test_it_rejects_a_language_that_is_not_offered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('locale', 'klingon')
            ->call('updateProfileInformation')
            ->assertHasErrors('locale');
    }

    public function test_it_rejects_an_unknown_timezone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('timezone', 'Mars/Olympus_Mons')
            ->call('updateProfileInformation')
            ->assertHasErrors('timezone');
    }

    public function test_the_chosen_language_is_applied_to_the_request(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_effective_timezone_falls_back_to_the_application_default(): void
    {
        config(['app.timezone' => 'UTC']);

        $user = User::factory()->create(['timezone' => null]);

        $this->assertSame('UTC', $user->effectiveTimezone());

        $user->timezone = 'America/Sao_Paulo';

        $this->assertSame('America/Sao_Paulo', $user->effectiveTimezone());
    }

    /**
     * A timezone that stops being valid must not take the page down with it.
     */
    public function test_the_effective_timezone_ignores_an_unknown_value(): void
    {
        config(['app.timezone' => 'UTC']);

        $user = User::factory()->create();

        $user->forceFill(['timezone' => 'Mars/Olympus_Mons']);

        $this->assertSame('UTC', $user->effectiveTimezone());
    }
}
