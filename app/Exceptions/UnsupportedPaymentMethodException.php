<?php

namespace App\Exceptions;

class UnsupportedPaymentMethodException extends BusinessRuleException
{
    public function __construct(string $method)
    {
        parent::__construct("Payment method '{$method}' is not supported.", 422);
    }
}
