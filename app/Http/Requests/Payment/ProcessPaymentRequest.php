<?php

namespace App\Http\Requests\Payment;

use App\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Base rules plus whatever the selected gateway declares for its own fields.
     * Adding a gateway therefore never requires editing this request.
     */
    public function rules(): array
    {
        $gateways = app(PaymentGatewayManager::class);
        $method = $this->input('payment_method');

        $rules = [
            'order_id' => ['required', 'integer'],
            'payment_method' => ['required', 'string', Rule::in($gateways->methods())],
        ];

        if (is_string($method) && $gateways->supports($method)) {
            $rules += $gateways->gateway($method)->rules();
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'payment_method.in' => 'The selected payment method is not supported. Supported methods: '
                .implode(', ', app(PaymentGatewayManager::class)->methods()).'.',
        ];
    }

    /**
     * Gateway-specific fields (everything except order_id / payment_method).
     *
     * @return array<string, mixed>
     */
    public function gatewayData(): array
    {
        return $this->safe()->except(['order_id', 'payment_method']);
    }
}
