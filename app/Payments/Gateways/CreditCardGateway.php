<?php

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\AbstractGateway;
use App\Payments\GatewayResult;
use Illuminate\Support\Carbon;

/**
 * Simulated credit-card gateway.
 *
 * Simulation rules (so both outcomes can be demonstrated and tested):
 *  - card 4000000000000002 is always declined;
 *  - expired cards are declined;
 *  - every other card is approved.
 *
 * Card data is only used for the charge call and is never persisted:
 * only the last four digits end up in the stored gateway response.
 */
class CreditCardGateway extends AbstractGateway
{
    public const DECLINED_TEST_CARD = '4000000000000002';

    public function rules(): array
    {
        return [
            'card_number' => ['required', 'string', 'regex:/^\d{13,19}$/'],
            'card_holder' => ['required', 'string', 'max:100'],
            'expiry_month' => ['required', 'integer', 'between:1,12'],
            'expiry_year' => ['required', 'integer', 'digits:4', 'min:'.now()->year],
            'cvv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
        ];
    }

    public function charge(Payment $payment, array $data): GatewayResult
    {
        $response = [
            'gateway' => 'credit_card',
            'card_last4' => substr((string) $data['card_number'], -4),
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
        ];

        $expiresAt = Carbon::create((int) $data['expiry_year'], (int) $data['expiry_month'], 1)->endOfMonth();

        if ($expiresAt->isPast()) {
            return GatewayResult::failure('The card has expired.', $response + ['code' => 'expired_card']);
        }

        if ((string) $data['card_number'] === self::DECLINED_TEST_CARD) {
            return GatewayResult::failure('The card was declined by the issuer.', $response + ['code' => 'card_declined']);
        }

        return GatewayResult::success(
            $this->newTransactionId('cc'),
            $response + ['code' => 'approved'],
            'Payment approved.',
        );
    }
}
