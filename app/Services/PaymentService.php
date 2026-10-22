<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\GatewayResult;
use App\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentService
{
    public function __construct(private readonly PaymentGatewayManager $gateways)
    {
    }

    public function process(Order $order, string $method, array $gatewayData = []): Payment
    {
        $gateway = $this->gateways->gateway($method);

        $payment = DB::transaction(function () use ($order, $method) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Confirmed) {
                throw new BusinessRuleException(
                    "Payments can only be processed for orders in confirmed status. Current status: {$locked->status->value}.",
                    422,
                );
            }

            if ($locked->payments()->where('status', PaymentStatus::Successful->value)->exists()) {
                throw new BusinessRuleException('This order has already been paid.', 409);
            }

            if ($locked->payments()->where('status', PaymentStatus::Pending->value)->exists()) {
                throw new BusinessRuleException('A payment for this order is already being processed.', 409);
            }

            return $locked->payments()->create([
                'payment_method' => $method,
                'status' => PaymentStatus::Pending,
                'amount' => $locked->total,
                'currency' => config('payment.currency', 'USD'),
            ]);
        });

        try {
            $result = $gateway->charge($payment, $gatewayData);
        } catch (Throwable $e) {
            report($e);

            $result = GatewayResult::failure('The payment gateway could not process the request. Please try again later.');
        }

        $payment->update([
            'status' => $result->successful ? PaymentStatus::Successful : PaymentStatus::Failed,
            'transaction_id' => $result->transactionId,
            'failure_reason' => $result->successful ? null : $result->message,
            'gateway_response' => $result->response ?: null,
        ]);

        return $payment;
    }
}
