<?php

namespace App\Http\Requests\Order\Concerns;

/**
 * Validation rules and messages shared by the create and update order requests.
 */
trait HasOrderRules
{
    /**
     * @param  string  $presence  'required' for create, 'sometimes' for update
     * @return array<string, mixed>
     */
    protected function orderRules(string $presence): array
    {
        return [
            'customer_name' => [$presence, 'string', 'max:255'],
            'customer_email' => [$presence, 'email:rfc', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'items' => [$presence, 'array', 'min:1', 'max:100'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function orderMessages(): array
    {
        return [
            'items.required' => 'An order must contain at least one item.',
            'items.min' => 'An order must contain at least one item.',
            'items.*.quantity.min' => 'Each item quantity must be at least 1.',
            'items.*.price.min' => 'Each item price must be greater than zero.',
            'items.*.price.decimal' => 'Each item price may have at most 2 decimal places.',
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.product_name' => 'item product name',
            'items.*.quantity' => 'item quantity',
            'items.*.price' => 'item price',
        ];
    }
}
