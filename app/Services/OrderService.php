<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function create(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data) {
            $order = $user->orders()->create([
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'status' => OrderStatus::Pending,
                'total' => $this->calculateTotal($data['items']),
            ]);

            $order->items()->createMany($this->itemRows($data['items']));

            return $order->load('items');
        });
    }

    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->status === OrderStatus::Cancelled) {
                throw new BusinessRuleException('Cancelled orders cannot be modified.', 409);
            }

            if (isset($data['items'])) {
                if ($order->status !== OrderStatus::Pending) {
                    throw new BusinessRuleException(
                        "Items can only be modified while the order is pending. Current status: {$order->status->value}.",
                        422,
                    );
                }

                $order->items()->delete();
                $order->items()->createMany($this->itemRows($data['items']));
                $order->total = $this->calculateTotal($data['items']);
            }

            if (isset($data['status'])) {
                $this->transition($order, OrderStatus::from($data['status']));
            }

            $order->fill(Arr::only($data, ['customer_name', 'customer_email', 'customer_phone']));
            $order->save();

            return $order->load('items');
        });
    }

    public function delete(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->payments()->exists()) {
                throw new BusinessRuleException('An order that has payments cannot be deleted.', 409);
            }

            $locked->delete();
        });
    }

    public function calculateTotal(array $items): string
    {
        $cents = 0;

        foreach ($items as $item) {
            $cents += (int) round(((float) $item['price']) * 100) * (int) $item['quantity'];
        }

        return number_format($cents / 100, 2, '.', '');
    }

    private function transition(Order $order, OrderStatus $target): void
    {
        if ($order->status === $target) {
            return;
        }

        if (! $order->status->canTransitionTo($target)) {
            throw new BusinessRuleException(
                "An order cannot move from '{$order->status->value}' to '{$target->value}'.",
                422,
            );
        }

        if ($target === OrderStatus::Cancelled
            && $order->payments()->where('status', PaymentStatus::Successful->value)->exists()) {
            throw new BusinessRuleException('An order with a successful payment cannot be cancelled.', 409);
        }

        $order->status = $target;
    }

    private function itemRows(array $items): array
    {
        return array_map(
            fn (array $item) => Arr::only($item, ['product_name', 'quantity', 'price']),
            array_values($items),
        );
    }
}
