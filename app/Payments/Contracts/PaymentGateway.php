<?php

namespace App\Payments\Contracts;

use App\Models\Payment;
use App\Payments\GatewayResult;

/**
 * Strategy interface implemented by every payment gateway.
 *
 * To add a gateway: implement this interface (usually by extending
 * App\Payments\AbstractGateway) and register it in config/payment.php.
 */
interface PaymentGateway
{
    /**
     * Validation rules for the gateway-specific fields of the "process payment"
     * request (e.g. card details for a card gateway, an email for PayPal).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Attempt to charge the given (pending) payment.
     *
     * Implementations should return a GatewayResult for expected outcomes
     * (approved / declined). Unexpected failures may throw; the PaymentService
     * converts them into a failed payment.
     *
     * @param  array<string, mixed>  $data  validated gateway-specific fields
     */
    public function charge(Payment $payment, array $data): GatewayResult;
}
