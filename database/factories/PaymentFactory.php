<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'payment_method' => 'credit_card',
            'status' => PaymentStatus::Pending,
            'amount' => 100.00,
            'currency' => 'USD',
            'transaction_id' => null,
            'failure_reason' => null,
            'gateway_response' => null,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Successful,
            'transaction_id' => 'cc_'.Str::lower(Str::random(16)),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed,
            'failure_reason' => 'The card was declined by the issuer.',
        ]);
    }
}
