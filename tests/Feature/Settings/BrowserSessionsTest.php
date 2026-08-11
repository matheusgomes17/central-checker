<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BrowserSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const string CHROME_ON_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const string FIREFOX_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_it_lists_the_sessions_belonging_to_the_user(): void
    {
        $user = User::factory()->create();

        $this->storeSession('session-one', $user, self::CHROME_ON_MAC, '203.0.113.10');
        $this->storeSession('session-two', $user, self::FIREFOX_ON_WINDOWS, '203.0.113.11');

        $this->actingAs($user);

        Livewire::test('pages::settings.browser-sessions')
            ->assertSee('Chrome on macOS')
            ->assertSee('Firefox on Windows')
            ->assertSee('203.0.113.10')
            ->assertSee('203.0.113.11');
    }

    /**
     * The phase acceptance criterion: one user must never be able to see
     * another user's sessions, which are rows in a table with no tenancy of
     * their own.
     */
    public function test_it_never_shows_another_users_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->storeSession('mine', $user, self::CHROME_ON_MAC, '203.0.113.10');
        $this->storeSession('theirs', $other, self::FIREFOX_ON_WINDOWS, '198.51.100.20');

        $this->actingAs($user);

        Livewire::test('pages::settings.browser-sessions')
            ->assertSee('203.0.113.10')
            ->assertDontSee('198.51.100.20')
            ->assertDontSee('Firefox on Windows');
    }

    public function test_it_signs_out_the_other_sessions_and_keeps_the_current_one(): void
    {
        $user = User::factory()->create();

        $current = session()->getId();

        $this->storeSession($current, $user, self::CHROME_ON_MAC, '203.0.113.10');
        $this->storeSession('elsewhere', $user, self::FIREFOX_ON_WINDOWS, '203.0.113.11');

        $this->actingAs($user);

        Livewire::test('pages::settings.browser-sessions')
            ->set('password', 'password')
            ->call('logoutOtherSessions')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sessions', ['id' => $current]);
        $this->assertDatabaseMissing('sessions', ['id' => 'elsewhere']);
    }

    public function test_it_never_signs_out_sessions_belonging_to_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->storeSession('theirs', $other, self::FIREFOX_ON_WINDOWS, '198.51.100.20');

        $this->actingAs($user);

        Livewire::test('pages::settings.browser-sessions')
            ->set('password', 'password')
            ->call('logoutOtherSessions')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sessions', ['id' => 'theirs']);
    }

    public function test_it_requires_the_correct_password(): void
    {
        $user = User::factory()->create();

        $this->storeSession('elsewhere', $user, self::FIREFOX_ON_WINDOWS, '203.0.113.11');

        $this->actingAs($user);

        Livewire::test('pages::settings.browser-sessions')
            ->set('password', 'not-the-password')
            ->call('logoutOtherSessions')
            ->assertHasErrors('password');

        $this->assertDatabaseHas('sessions', ['id' => 'elsewhere']);
    }

    /**
     * Reading rows out of the session table only works on the database driver.
     * On any other one the section has to disappear rather than render an
     * empty list next to a button that cannot do anything.
     */
    public function test_it_hides_itself_on_other_session_drivers(): void
    {
        config(['session.driver' => 'file']);

        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.browser-sessions')
            ->assertDontSee('Browser sessions')
            ->assertDontSee('Sign out other sessions');
    }

    private function storeSession(string $id, User $user, string $userAgent, string $ip): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);
    }
}
