<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;

class PaymentGatewayController extends Controller
{
    /**
     * Lists the enabled payment methods and the extra fields each one requires.
     */
    public function index(PaymentGatewayManager $gateways): JsonResponse
    {
        $data = array_map(fn (string $method) => [
            'payment_method' => $method,
            'required_fields' => array_keys($gateways->gateway($method)->rules()),
        ], $gateways->methods());

        return response()->json(['data' => array_values($data)]);
    }
}
