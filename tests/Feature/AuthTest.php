<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    private const TOKEN_STRUCTURE = ['access_token', 'token_type', 'expires_in', 'user' => ['id', 'name', 'email']];

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(self::TOKEN_STRUCTURE)
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertNotSame('secret-password', User::first()->password);
    }

    public function test_registration_is_validated(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane',
            'email' => 'not-an-email',
            'password' => 'short',
        ])->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'An account with this email address already exists.');
    }

    public function test_a_user_can_log_in(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'password' => 'secret-password']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(self::TOKEN_STRUCTURE);
    }

    public function test_login_fails_with_wrong_credentials(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'password' => 'secret-password']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_protected_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/payments')->assertUnauthorized();
        $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer not-a-real-token'])->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/v1/auth/me', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_the_token_from_login_works_on_protected_endpoints(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'password' => 'secret-password']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'secret-password',
        ])->json('access_token');

        $this->flushAuthState();

        $this->getJson('/api/v1/orders', ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    public function test_logout_invalidates_the_token(): void
    {
        $headers = $this->authHeaders();

        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();

        $this->flushAuthState();

        $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized();
    }

    public function test_a_token_can_be_refreshed(): void
    {
        $this->postJson('/api/v1/auth/refresh', [], $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(self::TOKEN_STRUCTURE);
    }
}
