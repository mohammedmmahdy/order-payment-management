<?php

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\AbstractGateway;
use App\Payments\GatewayResult;

/**
 * Simulated PayPal gateway.
 *
 * Simulation rule: a payer email containing the word "decline" is rejected,
 * everything else is approved. Credentials come from config/payment.php
 * (client_id, client_secret, mode) which in turn reads the .env file.
 */
class PayPalGateway extends AbstractGateway
{
    public function rules(): array
    {
        return [
            'paypal_email' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    public function charge(Payment $payment, array $data): GatewayResult
    {
        $response = [
            'gateway' => 'paypal',
            'payer_email' => $data['paypal_email'],
            'environment' => $this->config('mode', 'sandbox'),
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
        ];

        if (str_contains(strtolower((string) $data['paypal_email']), 'decline')) {
            return GatewayResult::failure('PayPal declined the payment.', $response + ['code' => 'payer_declined']);
        }

        return GatewayResult::success(
            $this->newTransactionId('pp'),
            $response + ['code' => 'approved'],
            'Payment approved.',
        );
    }
}
