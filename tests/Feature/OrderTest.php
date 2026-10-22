<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    // ---------------------------------------------------------------- create

    public function test_an_order_can_be_created_and_the_total_is_calculated(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/orders', $this->validOrderPayload(), $this->authHeaders($user));

        $response->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.customer.name', 'Jane Doe')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonStructure(['data' => [
                'id', 'status', 'customer' => ['name', 'email', 'phone'], 'total',
                'items' => [['id', 'product_name', 'quantity', 'price', 'subtotal']],
                'created_at', 'updated_at',
            ]]);

        // 2 x 10.50 + 1 x 5.00
        $this->assertEqualsWithDelta(26.0, $response->json('data.total'), 0.001);

        $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'total' => '26.00', 'status' => 'pending']);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_creating_an_order_requires_authentication(): void
    {
        $this->postJson('/api/v1/orders', $this->validOrderPayload())->assertUnauthorized();
    }

    public function test_creating_an_order_validates_the_payload(): void
    {
        $this->postJson('/api/v1/orders', [], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_name', 'customer_email', 'items']);
    }

    public function test_order_items_are_validated(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->validOrderPayload([
            'items' => [
                ['product_name' => '', 'quantity' => 0, 'price' => 0],
                ['product_name' => 'Cable', 'quantity' => 1.5, 'price' => 10.999],
                ['product_name' => 'Hub', 'quantity' => 1, 'price' => 'free'],
            ],
        ]), $this->authHeaders());

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'items.0.product_name',
            'items.0.quantity',
            'items.0.price',
            'items.1.quantity',
            'items.1.price',
            'items.2.price',
        ]);
    }

    public function test_an_order_needs_at_least_one_item(): void
    {
        $this->postJson('/api/v1/orders', $this->validOrderPayload(['items' => []]), $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonPath('errors.items.0', 'An order must contain at least one item.');
    }

    public function test_a_client_cannot_set_status_or_total_on_create(): void
    {
        $this->postJson(
            '/api/v1/orders',
            $this->validOrderPayload(['status' => 'confirmed', 'total' => 1, 'user_id' => 999]),
            $this->authHeaders(),
        )->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseMissing('orders', ['total' => '1.00']);
        $this->assertDatabaseMissing('orders', ['user_id' => 999]);
    }

    // ----------------------------------------------------------------- list

    public function test_orders_are_listed_per_user_with_pagination(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(3)->withItems()->for($user)->create();
        Order::factory()->count(2)->withItems()->create(); // someone else's

        $response = $this->getJson('/api/v1/orders?per_page=2', $this->authHeaders($user));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);

        $this->getJson('/api/v1/orders?per_page=2&page=2', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_orders_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->create();
        Order::factory()->count(2)->confirmed()->for($user)->create();
        Order::factory()->cancelled()->for($user)->create();

        $headers = $this->authHeaders($user);

        $this->getJson('/api/v1/orders?status=confirmed', $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'confirmed');

        $this->getJson('/api/v1/orders?status=pending', $headers)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?status=cancelled', $headers)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders', $headers)->assertJsonCount(4, 'data');
    }

    public function test_list_query_parameters_are_validated(): void
    {
        $headers = $this->authHeaders();

        $this->getJson('/api/v1/orders?status=shipped', $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->getJson('/api/v1/orders?per_page=1000', $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    // ----------------------------------------------------------------- show

    public function test_an_order_can_be_viewed(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->withItems(3)->for($user)->create();

        $this->getJson("/api/v1/orders/{$order->id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(3, 'data.items');
    }

    public function test_another_users_order_looks_like_a_missing_one(): void
    {
        $order = Order::factory()->create();

        $this->getJson("/api/v1/orders/{$order->id}", $this->authHeaders())
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found.');
    }

    public function test_unknown_endpoints_return_a_json_404(): void
    {
        $this->getJson('/api/v1/does-not-exist', $this->authHeaders())
            ->assertNotFound();
    }

    // --------------------------------------------------------------- update

    public function test_customer_details_can_be_updated(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->withItems()->for($user)->create();

        $this->putJson("/api/v1/orders/{$order->id}", [
            'customer_name' => 'John Smith',
            'customer_phone' => null,
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'John Smith')
            ->assertJsonPath('data.customer.phone', null);
    }

    public function test_replacing_items_recalculates_the_total(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->withItems(3)->for($user)->create();

        $response = $this->patchJson("/api/v1/orders/{$order->id}", [
            'items' => [['product_name' => 'Keyboard', 'quantity' => 3, 'price' => 19.99]],
        ], $this->authHeaders($user));

        $response->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertEqualsWithDelta(59.97, $response->json('data.total'), 0.001);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_an_order_can_be_confirmed_then_cancelled(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->withItems()->for($user)->create();
        $url = "/api/v1/orders/{$order->id}";

        $this->patchJson($url, ['status' => 'confirmed'], $this->authHeaders($user))
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->patchJson($url, ['status' => 'cancelled'], $this->authHeaders($user))
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->confirmed()->withItems()->for($user)->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'pending'], $this->authHeaders($user))
            ->assertUnprocessable()
            ->assertJsonPath('message', "An order cannot move from 'confirmed' to 'pending'.");
    }

    public function test_an_unknown_status_is_a_validation_error(): void
    {
        $order = Order::factory()->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'shipped'], $this->authHeaders(User::find($order->user_id)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_items_cannot_be_changed_once_the_order_is_confirmed(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->confirmed()->withItems()->for($user)->create();

        $this->patchJson("/api/v1/orders/{$order->id}", [
            'items' => [['product_name' => 'Sneaky', 'quantity' => 1, 'price' => 1]],
        ], $this->authHeaders($user))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Items can only be modified while the order is pending. Current status: confirmed.');
    }

    public function test_a_cancelled_order_cannot_be_modified(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->cancelled()->for($user)->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['customer_name' => 'New'], $this->authHeaders($user))
            ->assertConflict()
            ->assertJsonPath('message', 'Cancelled orders cannot be modified.');
    }

    public function test_an_order_with_a_successful_payment_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->confirmed()->withItems()->for($user)->create();
        Payment::factory()->successful()->for($order)->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'cancelled'], $this->authHeaders($user))
            ->assertConflict();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed']);
    }

    public function test_another_users_order_cannot_be_updated(): void
    {
        $order = Order::factory()->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['customer_name' => 'Hacked'], $this->authHeaders())
            ->assertNotFound();

        $this->assertDatabaseMissing('orders', ['customer_name' => 'Hacked']);
    }

    // --------------------------------------------------------------- delete

    public function test_an_order_without_payments_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->withItems()->for($user)->create();

        $this->deleteJson("/api/v1/orders/{$order->id}", [], $this->authHeaders($user))->assertNoContent();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_an_order_with_payments_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->confirmed()->withItems()->for($user)->create();
        Payment::factory()->failed()->for($order)->create(); // even a failed payment blocks deletion

        $this->deleteJson("/api/v1/orders/{$order->id}", [], $this->authHeaders($user))
            ->assertConflict()
            ->assertJsonPath('message', 'An order that has payments cannot be deleted.');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_another_users_order_cannot_be_deleted(): void
    {
        $order = Order::factory()->create();

        $this->deleteJson("/api/v1/orders/{$order->id}", [], $this->authHeaders())->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }
}
