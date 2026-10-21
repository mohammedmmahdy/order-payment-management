<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use App\Http\Requests\Order\Concerns\HasOrderRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    use HasOrderRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->orderRules('sometimes') + [
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
        ];
    }

    public function messages(): array
    {
        return $this->orderMessages() + [
            'status.enum' => 'The status must be one of: '.implode(', ', OrderStatus::values()).'.',
        ];
    }
}
