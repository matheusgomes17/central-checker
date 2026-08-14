<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_registration_screen_is_in_portuguese(): void
    {
        $this->get(route('register'))
            ->assertSee('Crie sua conta')
            ->assertSee('Nome completo')
            ->assertDontSee('Create an account');
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        User::where('email', 'test@example.com')->first();

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    /**
     * Nothing in Fortify limits registration, so a single source can mint
     * accounts as fast as it can post. Each one costs a row and a verification
     * email.
     */
    public function test_registration_is_rate_limited_per_source(): void
    {
        foreach (range(1, 5) as $index) {
            $this->postRegistration("user{$index}@example.com")
                ->assertSessionHasNoErrors();
        }

        $this->postRegistration('user6@example.com')->assertTooManyRequests();

        $this->assertDatabaseMissing('users', ['email' => 'user6@example.com']);
    }

    /**
     * @return TestResponse<Response>
     */
    private function postRegistration(string $email): TestResponse
    {
        return $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }
}
