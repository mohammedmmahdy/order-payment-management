<?php

namespace Tests\Unit\Payments;

use App\Exceptions\UnsupportedPaymentMethodException;
use App\Models\Payment;
use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PayPalGateway;
use App\Payments\PaymentGatewayManager;
use InvalidArgumentException;
use stdClass;
use Tests\Support\Gateways\FakeGateway;
use Tests\TestCase;

class PaymentGatewayManagerTest extends TestCase
{
    private function manager(): PaymentGatewayManager
    {
        return $this->app->make(PaymentGatewayManager::class);
    }

    public function test_lists_the_enabled_methods(): void
    {
        $this->assertSame(['credit_card', 'paypal'], $this->manager()->methods());
    }

    public function test_resolves_the_gateway_for_a_method(): void
    {
        $this->assertInstanceOf(CreditCardGateway::class, $this->manager()->gateway('credit_card'));
        $this->assertInstanceOf(PayPalGateway::class, $this->manager()->gateway('paypal'));
    }

    public function test_unknown_methods_are_rejected(): void
    {
        $this->expectException(UnsupportedPaymentMethodException::class);

        $this->manager()->gateway('bitcoin');
    }

    public function test_disabled_gateways_are_not_available(): void
    {
        config(['payment.gateways.paypal.enabled' => false]);

        $this->assertSame(['credit_card'], $this->manager()->methods());
        $this->assertFalse($this->manager()->supports('paypal'));
    }

    public function test_a_new_gateway_can_be_added_through_configuration_only(): void
    {
        config(['payment.gateways.fake' => [
            'driver' => FakeGateway::class,
            'enabled' => true,
            'api_key' => 'secret-key',
        ]]);

        $manager = $this->manager();

        $this->assertTrue($manager->supports('fake'));
        $this->assertContains('fake', $manager->methods());
        $this->assertInstanceOf(FakeGateway::class, $manager->gateway('fake'));
    }

    public function test_gateways_receive_their_config_block_but_not_driver_or_enabled(): void
    {
        config(['payment.gateways.fake' => [
            'driver' => FakeGateway::class,
            'enabled' => true,
            'api_key' => 'secret-key',
        ]]);

        $payment = new Payment(['amount' => '1.00', 'currency' => 'USD']);
        $result = $this->manager()->gateway('fake')->charge($payment, ['token' => 'tok_ok']);

        $this->assertTrue($result->successful);
        $this->assertSame('fake_secret-key', $result->transactionId);
    }

    public function test_a_driver_that_is_not_a_payment_gateway_is_rejected(): void
    {
        config(['payment.gateways.broken' => ['driver' => stdClass::class]]);

        $this->expectException(InvalidArgumentException::class);

        $this->manager()->gateway('broken');
    }

    public function test_a_missing_driver_class_is_rejected(): void
    {
        config(['payment.gateways.broken' => ['driver' => 'App\\Nope\\MissingGateway']]);

        $this->expectException(InvalidArgumentException::class);

        $this->manager()->gateway('broken');
    }
}
