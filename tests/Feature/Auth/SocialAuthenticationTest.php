<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SocialAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-secret',
            'services.github.client_id' => 'github-client-id',
            'services.github.client_secret' => 'github-secret',
        ]);
    }

    public function test_it_redirects_to_the_provider(): void
    {
        Socialite::fake('github');

        $this->get(route('social.redirect', 'github'))->assertRedirect();
    }

    public function test_it_rejects_a_provider_that_is_not_enabled(): void
    {
        $this->get(route('social.redirect', 'facebook'))->assertNotFound();
        $this->get(route('social.callback', 'facebook'))->assertNotFound();
    }

    public function test_it_rejects_an_enabled_provider_without_credentials(): void
    {
        config(['services.github.client_id' => null]);

        $this->get(route('social.redirect', 'github'))->assertNotFound();
    }

    public function test_it_creates_a_verified_account_and_a_personal_team(): void
    {
        Socialite::fake('github', $this->socialUser('gh-1', 'ada@example.com', 'Ada Lovelace'));

        $this->get(route('social.callback', 'github'))->assertRedirect();

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'github',
            'provider_id' => 'gh-1',
        ]);
        $this->assertTrue($user->ownedTeams()->where('is_personal', true)->exists());
    }

    public function test_a_returning_user_signs_in_without_a_second_account(): void
    {
        Socialite::fake('github', $this->socialUser('gh-1', 'ada@example.com', 'Ada Lovelace'));
        $this->get(route('social.callback', 'github'));

        $this->post(route('logout'));

        Socialite::fake('github', $this->socialUser('gh-1', 'ada@example.com', 'Ada Lovelace'));
        $this->get(route('social.callback', 'github'))->assertRedirect();

        $this->assertSame(1, User::count());
        $this->assertSame(1, SocialAccount::count());
    }

    /**
     * The link is keyed on the provider's subject id, never the email. A
     * person who changes their address at the provider has to keep signing in
     * to the same local account.
     */
    public function test_the_link_survives_an_email_change_at_the_provider(): void
    {
        Socialite::fake('github', $this->socialUser('gh-1', 'ada@example.com', 'Ada Lovelace'));
        $this->get(route('social.callback', 'github'));

        $user = User::firstOrFail();
        $this->post(route('logout'));

        Socialite::fake('github', $this->socialUser('gh-1', 'ada@newdomain.example', 'Ada Lovelace'));
        $this->get(route('social.callback', 'github'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        $this->assertSame('ada@example.com', $user->refresh()->email);
    }

    public function test_a_verified_provider_email_links_to_the_matching_account(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        Socialite::fake('github', $this->socialUser('gh-1', 'ada@example.com', 'Ada Lovelace'));

        $this->get(route('social.callback', 'github'))->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'github',
        ]);
    }

    /**
     * The takeover scenario. Google tells us whether it verified the address;
     * if it did not, matching on that address would hand the local account to
     * whoever typed it into their Google profile.
     */
    public function test_an_unverified_provider_email_never_links_to_an_account(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);

        Socialite::fake('google', $this->socialUser(
            'goog-1',
            'victim@example.com',
            'Attacker',
            ['email_verified' => false],
        ));

        $this->get(route('social.callback', 'google'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertSame(1, User::count());
        $this->assertSame($victim->id, User::firstOrFail()->id);
    }

    public function test_an_unverified_provider_email_never_creates_an_account(): void
    {
        Socialite::fake('google', $this->socialUser(
            'goog-1',
            'squatter@example.com',
            'Squatter',
            ['email_verified' => false],
        ));

        $this->get(route('social.callback', 'google'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_google_account_with_a_verified_email_signs_in(): void
    {
        Socialite::fake('google', $this->socialUser(
            'goog-1',
            'ada@example.com',
            'Ada Lovelace',
            ['email_verified' => true],
        ));

        $this->get(route('social.callback', 'google'))->assertRedirect();

        $this->assertAuthenticatedAs(User::firstOrFail());
    }

    public function test_a_provider_that_shares_no_email_is_refused(): void
    {
        Socialite::fake('github', $this->socialUser('gh-1', null, 'Ada Lovelace'));

        $this->get(route('social.callback', 'github'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    /**
     * A deleted account still holds its email during the retention window.
     * Social sign in must not resurrect it, nor blow up on the unique index.
     */
    public function test_it_refuses_an_account_inside_the_deletion_retention_window(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $user->delete();

        Socialite::fake('github', $this->socialUser('gh-1', 'ada@example.com', 'Ada Lovelace'));

        $this->get(route('social.callback', 'github'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_a_declined_authorization_returns_to_the_login_screen(): void
    {
        // Sem Socialite::fake(), pedir o usuário no callback estoura — que é o
        // que acontece quando a pessoa recusa a autorização.
        $this->get(route('social.callback', 'github'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_login_screen_offers_the_configured_providers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google')
            ->assertSee('Continue with GitHub');
    }

    /**
     * A button that leads to a 404 for want of a credential is worse than no
     * button at all.
     */
    public function test_the_login_screen_hides_a_provider_without_credentials(): void
    {
        config(['services.github.client_id' => null]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google')
            ->assertDontSee('Continue with GitHub');
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function socialUser(string $id, ?string $email, string $name, array $raw = []): SocialiteUser
    {
        $user = new SocialiteUser;

        $user->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
        ]);

        $user->setRaw($raw + ['id' => $id, 'email' => $email]);

        return $user;
    }
}
