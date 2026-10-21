<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => fake()->numerify('+20 1#########'),
            'status' => OrderStatus::Pending,
            'total' => 0,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Cancelled]);
    }

    /**
     * Attach items and keep the order total consistent with them.
     */
    public function withItems(int $count = 2): static
    {
        return $this->afterCreating(function (Order $order) use ($count) {
            $items = OrderItem::factory()->count($count)->for($order)->create();

            $order->update([
                'total' => number_format(
                    $items->sum(fn (OrderItem $item) => $item->quantity * (float) $item->price),
                    2,
                    '.',
                    ''
                ),
            ]);
        });
    }
}
