<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Gateways\ExplodingGateway;
use Tests\Support\Gateways\FakeGateway;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PaymentService
    {
        return $this->app->make(PaymentService::class);
    }

    private function confirmedOrder(): Order
    {
        return Order::factory()->confirmed()->withItems()->create();
    }

    private function card(array $overrides = []): array
    {
        return array_replace([
            'card_number' => '4242424242424242',
            'card_holder' => 'Jane Doe',
            'expiry_month' => 12,
            'expiry_year' => now()->year + 2,
            'cvv' => '123',
        ], $overrides);
    }

    public function test_successful_payment_is_stored_with_the_order_total(): void
    {
        $order = $this->confirmedOrder();

        $payment = $this->service()->process($order, 'credit_card', $this->card());

        $this->assertSame(PaymentStatus::Successful, $payment->status);
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame((string) $order->total, (string) $payment->amount);
        $this->assertSame('USD', $payment->currency);
        $this->assertNotNull($payment->transaction_id);
        $this->assertNull($payment->failure_reason);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'successful']);
    }

    public function test_declined_payment_is_stored_as_failed_with_a_reason(): void
    {
        $payment = $this->service()->process(
            $this->confirmedOrder(),
            'credit_card',
            $this->card(['card_number' => '4000000000000002']),
        );

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertNull($payment->transaction_id);
        $this->assertSame('The card was declined by the issuer.', $payment->failure_reason);
    }

    public function test_card_details_are_never_persisted(): void
    {
        $payment = $this->service()->process($this->confirmedOrder(), 'credit_card', $this->card());

        $stored = json_encode(Payment::query()->findOrFail($payment->id)->toArray());

        $this->assertStringNotContainsString('4242424242424242', $stored);
        $this->assertStringContainsString('4242', $stored);
    }

    public function test_only_confirmed_orders_can_be_paid(): void
    {
        foreach ([Order::factory()->withItems()->create(), Order::factory()->cancelled()->withItems()->create()] as $order) {
            try {
                $this->service()->process($order, 'credit_card', $this->card());
                $this->fail('Expected a BusinessRuleException.');
            } catch (BusinessRuleException $e) {
                $this->assertSame(422, $e->status());
                $this->assertStringContainsString('confirmed status', $e->getMessage());
            }
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_already_paid_order_cannot_be_paid_again(): void
    {
        $order = $this->confirmedOrder();
        $this->service()->process($order, 'credit_card', $this->card());

        try {
            $this->service()->process($order, 'paypal', ['paypal_email' => 'buyer@example.com']);
            $this->fail('Expected a BusinessRuleException.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->status());
        }

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_failed_payment_can_be_retried(): void
    {
        $order = $this->confirmedOrder();

        $first = $this->service()->process($order, 'credit_card', $this->card(['card_number' => '4000000000000002']));
        $second = $this->service()->process($order, 'credit_card', $this->card());

        $this->assertSame(PaymentStatus::Failed, $first->status);
        $this->assertSame(PaymentStatus::Successful, $second->status);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_a_payment_already_in_flight_blocks_a_second_attempt(): void
    {
        $order = $this->confirmedOrder();
        Payment::factory()->for($order)->create(); // pending

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already being processed');

        $this->service()->process($order, 'credit_card', $this->card());
    }

    public function test_unsupported_method_is_rejected_before_anything_is_stored(): void
    {
        $order = $this->confirmedOrder();

        try {
            $this->service()->process($order, 'bitcoin');
            $this->fail('Expected an UnsupportedPaymentMethodException.');
        } catch (UnsupportedPaymentMethodException $e) {
            $this->assertSame(422, $e->status());
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_gateway_exception_marks_the_payment_failed(): void
    {
        config(['payment.gateways.exploding' => ['driver' => ExplodingGateway::class]]);

        $payment = $this->service()->process($this->confirmedOrder(), 'exploding');

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertStringContainsString('could not process', $payment->failure_reason);
        $this->assertStringNotContainsString('timed out', $payment->failure_reason);
    }

    public function test_a_newly_configured_gateway_is_used_without_changing_the_service(): void
    {
        config(['payment.gateways.fake' => ['driver' => FakeGateway::class, 'api_key' => 'k1']]);

        $payment = $this->service()->process($this->confirmedOrder(), 'fake', ['token' => 'tok_ok']);

        $this->assertSame(PaymentStatus::Successful, $payment->status);
        $this->assertSame('fake_k1', $payment->transaction_id);
        $this->assertSame('fake', $payment->payment_method);
    }
}
