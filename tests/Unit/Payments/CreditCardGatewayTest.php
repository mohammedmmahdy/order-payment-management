<?php

namespace Tests\Unit\Payments;

use App\Models\Payment;
use App\Payments\Gateways\CreditCardGateway;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CreditCardGatewayTest extends TestCase
{
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

    private function payment(): Payment
    {
        return new Payment(['amount' => '26.00', 'currency' => 'USD']);
    }

    public function test_approves_a_valid_card(): void
    {
        $result = (new CreditCardGateway())->charge($this->payment(), $this->card());

        $this->assertTrue($result->successful);
        $this->assertStringStartsWith('cc_', $result->transactionId);
        $this->assertSame('4242', $result->response['card_last4']);
        $this->assertSame('approved', $result->response['code']);
    }

    public function test_declines_the_decline_test_card(): void
    {
        $result = (new CreditCardGateway())->charge(
            $this->payment(),
            $this->card(['card_number' => CreditCardGateway::DECLINED_TEST_CARD]),
        );

        $this->assertFalse($result->successful);
        $this->assertNull($result->transactionId);
        $this->assertSame('card_declined', $result->response['code']);
    }

    public function test_declines_an_expired_card(): void
    {
        $result = (new CreditCardGateway())->charge(
            $this->payment(),
            $this->card(['expiry_month' => 1, 'expiry_year' => now()->year - 1]),
        );

        $this->assertFalse($result->successful);
        $this->assertSame('expired_card', $result->response['code']);
    }

    public function test_never_exposes_the_full_card_number_or_cvv_in_the_response(): void
    {
        $result = (new CreditCardGateway())->charge($this->payment(), $this->card());

        $encoded = json_encode($result->response);

        $this->assertStringNotContainsString('4242424242424242', $encoded);
        $this->assertStringNotContainsString('"123"', $encoded);
    }

    public function test_rules_accept_valid_card_data(): void
    {
        $validator = Validator::make($this->card(), (new CreditCardGateway())->rules());

        $this->assertFalse($validator->fails());
    }

    public function test_rules_reject_invalid_card_data(): void
    {
        $validator = Validator::make(
            $this->card(['card_number' => '12ab', 'expiry_month' => 13, 'cvv' => '1']),
            (new CreditCardGateway())->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertEqualsCanonicalizing(
            ['card_number', 'expiry_month', 'cvv'],
            array_keys($validator->errors()->toArray()),
        );
    }
}
