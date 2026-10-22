<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ListPaymentsRequest;
use App\Http\Requests\Payment\ProcessPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** All payments of the authenticated user, optionally filtered by status / order_id. */
    public function index(ListPaymentsRequest $request): AnonymousResourceCollection
    {
        $payments = Payment::query()
            ->forUser($request->user())
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->validated('order_id'), fn ($q, $orderId) => $q->where('order_id', $orderId))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return PaymentResource::collection($payments);
    }

    /** Payments of one order. */
    public function forOrder(ListPaymentsRequest $request, string $order): AnonymousResourceCollection
    {
        $order = $request->user()->orders()->findOrFail($order);

        $payments = $order->payments()
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return PaymentResource::collection($payments);
    }

    /**
     * Process a payment: 201 when the gateway approves it,
     * 402 (Payment Required) with the stored payment when it fails.
     */
    public function store(ProcessPaymentRequest $request): JsonResponse
    {
        $order = $request->user()->orders()->findOrFail($request->validated('order_id'));

        $payment = $this->payments->process(
            $order,
            $request->validated('payment_method'),
            $request->gatewayData(),
        );

        $successful = $payment->status === PaymentStatus::Successful;

        return (new PaymentResource($payment))
            ->additional([
                'message' => $successful ? 'Payment processed successfully.' : 'Payment failed.',
            ])
            ->response()
            ->setStatusCode($successful ? 201 : 402);
    }

    public function show(Request $request, string $payment): PaymentResource|JsonResponse
    {
        $payment = Payment::query()->forUser($request->user())->find($payment);
        if (! $payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        return new PaymentResource($payment);
    }
}
