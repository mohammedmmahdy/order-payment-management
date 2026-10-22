<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ListOrdersRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $orders = $request->user()
            ->orders()
            ->with('items')
            ->withStatus($request->validated('status'))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->user(), $request->validated());

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('orders.show', ['order' => $order->id]));
    }

    public function show(Request $request, string $order): OrderResource
    {
        return new OrderResource($this->findOwned($request, $order));
    }

    public function update(UpdateOrderRequest $request, string $order): OrderResource
    {
        $updated = $this->orders->update($this->findOwned($request, $order), $request->validated());

        return new OrderResource($updated);
    }

    public function destroy(Request $request, string $order): Response
    {
        $this->orders->delete($this->findOwned($request, $order));

        return response()->noContent();
    }

    /**
     * Orders are always looked up through the authenticated user, so another
     * user's order is indistinguishable from a missing one (404).
     */
    private function findOwned(Request $request, string $id): Order
    {
        $order = $request->user()->orders()->with('items')->find($id);
        if (!$order) { abort(404, 'Order not found.');}
        return $order;
    }
}
