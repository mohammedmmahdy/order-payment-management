<?php

namespace Tests\Concerns;

use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

trait InteractsWithJwt
{
    /**
     * Headers carrying a real JWT for the given user (a new user when omitted).
     *
     * Call it right before each request when switching between users in one
     * test: it also clears the guard / token cached by the previous request,
     * which would otherwise leak between requests inside a single test.
     *
     * @return array<string, string>
     */
    protected function authHeaders(?User $user = null): array
    {
        $user ??= User::factory()->create();

        $this->flushAuthState();

        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'Accept' => 'application/json',
        ];
    }

    protected function flushAuthState(): void
    {
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validOrderPayload(array $overrides = []): array
    {
        return array_replace([
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+201001234567',
            'items' => [
                ['product_name' => 'Wireless Mouse', 'quantity' => 2, 'price' => 10.50],
                ['product_name' => 'USB-C Cable', 'quantity' => 1, 'price' => 5.00],
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validCardPayload(array $overrides = []): array
    {
        return array_replace([
            'card_number' => '4242424242424242',
            'card_holder' => 'Jane Doe',
            'expiry_month' => 12,
            'expiry_year' => now()->year + 2,
            'cvv' => '123',
        ], $overrides);
    }
}
