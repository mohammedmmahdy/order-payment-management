<?php

namespace Tests\Support\Gateways;

use App\Models\Payment;
use App\Payments\AbstractGateway;
use App\Payments\GatewayResult;
use RuntimeException;

/**
 * Simulates a gateway outage (network error, 5xx, ...).
 */
class ExplodingGateway extends AbstractGateway
{
    public function charge(Payment $payment, array $data): GatewayResult
    {
        throw new RuntimeException('Gateway timed out');
    }
}
