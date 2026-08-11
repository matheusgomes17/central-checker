<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification): true {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    /**
     * The password broker already throttles repeated requests for the same
     * address, but that limit is per email: one source can still walk a list
     * of addresses and mail every one of them. The plan asks for a named
     * limiter on reset, and this is the hole it has to close.
     */
    public function test_reset_link_requests_are_rate_limited_per_source(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $ignored) {
            $this->post(route('password.request'), ['email' => $this->freshUserEmail()])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('password.request'), ['email' => $this->freshUserEmail()])
            ->assertTooManyRequests();
    }

    public function test_password_reset_submissions_are_rate_limited_per_source(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->postReset($this->freshUserEmail());
        }

        $this->postReset($this->freshUserEmail())->assertTooManyRequests();
    }

    private function freshUserEmail(): string
    {
        return User::factory()->create()->email;
    }

    /**
     * @return TestResponse<Response>
     */
    private function postReset(string $email): TestResponse
    {
        return $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): true {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            return true;
        });
    }
}
