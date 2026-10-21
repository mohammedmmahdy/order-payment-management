<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\Order\Concerns\HasOrderRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    use HasOrderRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->orderRules('required');
    }

    public function messages(): array
    {
        return $this->orderMessages();
    }
}
