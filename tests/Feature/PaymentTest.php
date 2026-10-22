<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithJwt;
use Tests\Support\Gateways\ExplodingGateway;
use Tests\Support\Gateways\FakeGateway;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    private function confirmedOrderFor(User $user): Order
    {
        return Order::factory()->confirmed()->withItems()->for($user)->create();
    }

    // ------------------------------------------------------ process payment

    public function test_a_credit_card_payment_succeeds_for_a_confirmed_order(): void
    {
        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);

        $response = $this->postJson('/api/v1/payments', $this->validCardPayload([
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
        ]), $this->authHeaders($user));

        $response->assertCreated()
            ->assertJsonPath('message', 'Payment processed successfully.')
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.payment_method', 'credit_card')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.gateway_response.card_last4', '4242')
            ->assertJsonStructure(['data' => ['id', 'transaction_id', 'amount', 'created_at']]);

        $this->assertEqualsWithDelta((float) $order->total, $response->json('data.amount'), 0.001);
        $this->assertStringStartsWith('cc_', $response->json('data.transaction_id'));

        // card number / cvv must never be echoed back or stored
        $response->assertDontSee('4242424242424242');
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'successful']);
    }

    public function test_a_paypal_payment_succeeds(): void
    {
        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'payment_method' => 'paypal',
            'paypal_email' => 'buyer@example.com',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'paypal')
            ->assertJsonPath('data.status', 'successful');
    }

    public function test_a_declined_payment_returns_402_and_is_recorded_as_failed(): void
    {
        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);

        $this->postJson('/api/v1/payments', $this->validCardPayload([
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
            'card_number' => '4000000000000002',
        ]), $this->authHeaders($user))
            ->assertStatus(402)
            ->assertJsonPath('message', 'Payment failed.')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_reason', 'The card was declined by the issuer.')
            ->assertJsonPath('data.transaction_id', null);

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'failed']);
    }

    public function test_a_failed_payment_can_be_retried_successfully(): void
    {
        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);
        $headers = $this->authHeaders($user);

        $this->postJson('/api/v1/payments', $this->validCardPayload([
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
            'card_number' => '4000000000000002',
        ]), $headers)->assertStatus(402);

        $this->postJson('/api/v1/payments', $this->validCardPayload([
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
        ]), $headers)->assertCreated();

        $this->assertDatabaseCount('payments', 2);
    }

    // --------------------------------------------------------- business rules

    public function test_a_pending_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->withItems()->for($user)->create();

        $this->postJson('/api/v1/payments', $this->validCardPayload([
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
        ]), $this->authHeaders($user))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Payments can only be processed for orders in confirmed status. Current status: pending.');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_cancelled_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->cancelled()->withItems()->for($user)->create();

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'payment_method' => 'paypal',
            'paypal_email' => 'buyer@example.com',
        ], $this->authHeaders($user))->assertUnprocessable();
    }

    public function test_an_order_cannot_be_paid_twice(): void
    {
        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);
        Payment::factory()->successful()->for($order)->create();

        $this->postJson('/api/v1/payments', $this->validCardPayload([
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
        ]), $this->authHeaders($user))
            ->assertConflict()
            ->assertJsonPath('message', 'This order has already been paid.');
    }

    public function test_another_users_order_cannot_be_paid(): void
    {
        $order = $this->confirmedOrderFor(User::factory()->create());

        $this->postJson('/api/v1/payments', $this->validCardPayload([
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
        ]), $this->authHeaders())->assertNotFound();
    }

    public function test_paying_requires_authentication(): void
    {
        $this->postJson('/api/v1/payments', [])->assertUnauthorized();
    }

    // ------------------------------------------------------------ validation

    public function test_order_id_and_payment_method_are_required(): void
    {
        $this->postJson('/api/v1/payments', [], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_id', 'payment_method']);
    }

    public function test_an_unsupported_payment_method_is_rejected_with_a_helpful_message(): void
    {
        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'payment_method' => 'bitcoin',
        ], $this->authHeaders($user))
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_method.0', 'The selected payment method is not supported. Supported methods: credit_card, paypal.');
    }

    public function test_gateway_specific_fields_are_validated(): void
    {
        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);
        $headers = $this->authHeaders($user);

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors([
            'card_number', 'card_holder', 'expiry_month', 'expiry_year', 'cvv',
        ]);

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'payment_method' => 'paypal',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['paypal_email'])
            ->assertJsonMissingValidationErrors(['card_number']);

        $this->assertDatabaseCount('payments', 0);
    }

    // ------------------------------------------------------------- extensibility

    public function test_a_gateway_added_via_config_works_end_to_end(): void
    {
        config(['payment.gateways.fake' => ['driver' => FakeGateway::class, 'api_key' => 'k1']]);

        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);
        $headers = $this->authHeaders($user);

        // its own validation rules are enforced...
        $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'payment_method' => 'fake'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        // ...and it processes payments like any other gateway
        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'payment_method' => 'fake',
            'token' => 'tok_ok',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'fake')
            ->assertJsonPath('data.transaction_id', 'fake_k1');
    }

    public function test_a_gateway_outage_results_in_a_failed_payment_without_leaking_details(): void
    {
        config(['payment.gateways.exploding' => ['driver' => ExplodingGateway::class]]);

        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'payment_method' => 'exploding',
        ], $this->authHeaders($user))
            ->assertStatus(402)
            ->assertJsonPath('data.status', 'failed')
            ->assertDontSee('timed out');
    }

    public function test_the_available_gateways_are_listed(): void
    {
        $this->getJson('/api/v1/payment-gateways', $this->authHeaders())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.payment_method', 'credit_card')
            ->assertJsonPath('data.0.required_fields', ['card_number', 'card_holder', 'expiry_month', 'expiry_year', 'cvv'])
            ->assertJsonPath('data.1.payment_method', 'paypal')
            ->assertJsonPath('data.1.required_fields', ['paypal_email']);
    }

    // ------------------------------------------------------------ view payments

    public function test_payments_of_a_specific_order_can_be_listed(): void
    {
        $user = User::factory()->create();
        $order = $this->confirmedOrderFor($user);
        $other = $this->confirmedOrderFor($user);
        Payment::factory()->failed()->for($order)->create();
        Payment::factory()->successful()->for($order)->create();
        Payment::factory()->successful()->for($other)->create();

        $this->getJson("/api/v1/orders/{$order->id}/payments", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->getJson("/api/v1/orders/{$order->id}/payments?status=failed", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_payments_of_another_users_order_are_not_visible(): void
    {
        $order = $this->confirmedOrderFor(User::factory()->create());
        Payment::factory()->successful()->for($order)->create();

        $this->getJson("/api/v1/orders/{$order->id}/payments", $this->authHeaders())->assertNotFound();
    }

    public function test_all_payments_can_be_listed_filtered_and_paginated(): void
    {
        $user = User::factory()->create();
        $first = $this->confirmedOrderFor($user);
        $second = $this->confirmedOrderFor($user);
        Payment::factory()->count(2)->successful()->for($first)->create();
        Payment::factory()->failed()->for($second)->create();
        Payment::factory()->successful()->create(); // belongs to another user

        $headers = $this->authHeaders($user);

        $this->getJson('/api/v1/payments', $headers)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/payments?per_page=2', $headers)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/payments?status=failed', $headers)->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/payments?order_id={$first->id}", $headers)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/payments?status=bogus', $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_a_single_payment_can_be_viewed(): void
    {
        $user = User::factory()->create();
        $payment = Payment::factory()->successful()->for($this->confirmedOrderFor($user))->create();

        $this->getJson("/api/v1/payments/{$payment->id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.status', 'successful');
    }

    public function test_another_users_payment_looks_like_a_missing_one(): void
    {
        $payment = Payment::factory()->successful()->create();

        $this->getJson("/api/v1/payments/{$payment->id}", $this->authHeaders())
            ->assertNotFound()
            ->assertJsonPath('message', 'Payment not found.');
    }
}
