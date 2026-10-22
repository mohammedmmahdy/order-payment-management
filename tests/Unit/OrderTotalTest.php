<?php

namespace Tests\Unit;

use App\Services\OrderService;
use PHPUnit\Framework\TestCase;

class OrderTotalTest extends TestCase
{
    public function test_total_is_the_sum_of_quantity_times_price(): void
    {
        $total = (new OrderService)->calculateTotal([
            ['quantity' => 2, 'price' => 10.50],
            ['quantity' => 1, 'price' => 5],
        ]);

        $this->assertSame('26.00', $total);
    }

    public function test_total_does_not_suffer_from_float_drift(): void
    {
        $service = new OrderService;

        $this->assertSame('0.30', $service->calculateTotal([['quantity' => 3, 'price' => 0.1]]));
        $this->assertSame('59.97', $service->calculateTotal([['quantity' => 3, 'price' => '19.99']]));
    }
}
