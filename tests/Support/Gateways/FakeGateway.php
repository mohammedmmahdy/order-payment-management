<?php

namespace Tests\Support\Gateways;

use App\Models\Payment;
use App\Payments\AbstractGateway;
use App\Payments\GatewayResult;

/**
 * A brand-new gateway added in tests with zero changes to application code:
 * the only "wiring" is a config entry (see PaymentGatewayManagerTest).
 */
class FakeGateway extends AbstractGateway
{
    public function rules(): array
    {
        return ['token' => ['required', 'string']];
    }

    public function charge(Payment $payment, array $data): GatewayResult
    {
        if ($data['token'] === 'tok_fail') {
            return GatewayResult::failure('Fake gateway refused the token.');
        }

        return GatewayResult::success('fake_'.$this->config('api_key', 'nokey'), ['token' => $data['token']]);
    }
}
