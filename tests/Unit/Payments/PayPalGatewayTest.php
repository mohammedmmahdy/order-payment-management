<?php

namespace Tests\Unit\Payments;

use App\Models\Payment;
use App\Payments\Gateways\PayPalGateway;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PayPalGatewayTest extends TestCase
{
    private function payment(): Payment
    {
        return new Payment(['amount' => '26.00', 'currency' => 'USD']);
    }

    public function test_approves_a_normal_payer(): void
    {
        $gateway = new PayPalGateway(['mode' => 'sandbox']);

        $result = $gateway->charge($this->payment(), ['paypal_email' => 'buyer@example.com']);

        $this->assertTrue($result->successful);
        $this->assertStringStartsWith('pp_', $result->transactionId);
        $this->assertSame('sandbox', $result->response['environment']);
    }

    public function test_declines_a_payer_flagged_for_decline(): void
    {
        $result = (new PayPalGateway())->charge($this->payment(), ['paypal_email' => 'decline@example.com']);

        $this->assertFalse($result->successful);
        $this->assertSame('PayPal declined the payment.', $result->message);
    }

    public function test_requires_a_valid_paypal_email(): void
    {
        $gateway = new PayPalGateway();

        $this->assertTrue(Validator::make([], $gateway->rules())->fails());
        $this->assertTrue(Validator::make(['paypal_email' => 'not-an-email'], $gateway->rules())->fails());
        $this->assertFalse(Validator::make(['paypal_email' => 'buyer@example.com'], $gateway->rules())->fails());
    }
}
