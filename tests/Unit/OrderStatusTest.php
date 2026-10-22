<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    /**
     * @return array<string, array{OrderStatus, OrderStatus, bool}>
     */
    public static function transitions(): array
    {
        return [
            'pending -> confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed, true],
            'pending -> cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled, true],
            'confirmed -> cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled, true],
            'confirmed -> pending' => [OrderStatus::Confirmed, OrderStatus::Pending, false],
            'cancelled -> pending' => [OrderStatus::Cancelled, OrderStatus::Pending, false],
            'cancelled -> confirmed' => [OrderStatus::Cancelled, OrderStatus::Confirmed, false],
            'pending -> pending' => [OrderStatus::Pending, OrderStatus::Pending, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_transition_rules(OrderStatus $from, OrderStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_values_lists_every_status(): void
    {
        $this->assertSame(['pending', 'confirmed', 'cancelled'], OrderStatus::values());
    }
}
