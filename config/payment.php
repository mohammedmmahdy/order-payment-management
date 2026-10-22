<?php

use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PayPalGateway;

/*
|--------------------------------------------------------------------------
| Payment gateways
|--------------------------------------------------------------------------
|
| Each key is the `payment_method` value accepted by the API. To add a
| gateway, create a class implementing App\Payments\Contracts\PaymentGateway
| and add one entry here. Everything except `driver` and `enabled` is passed
| to the gateway as its config (API keys, secrets, mode, ...), and values
| are read from the .env file.
|
*/

return [

    'currency' => env('PAYMENT_CURRENCY', 'USD'),

    'gateways' => [

        'credit_card' => [
            'driver' => CreditCardGateway::class,
            'enabled' => env('PAYMENT_CREDIT_CARD_ENABLED', true),
            'api_key' => env('CREDIT_CARD_API_KEY'),
            'api_secret' => env('CREDIT_CARD_API_SECRET'),
        ],

        'paypal' => [
            'driver' => PayPalGateway::class,
            'enabled' => env('PAYMENT_PAYPAL_ENABLED', true),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'mode' => env('PAYPAL_MODE', 'sandbox'),
        ],

    ],

];
