<?php

namespace App\Http\Controllers\Pos;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CheckoutRequest;
use App\Http\Requests\Pos\OrderDiscountRequest;
use App\Http\Requests\Pos\PayRequest;
use App\Http\Requests\Pos\ServiceStatusRequest;
use App\Http\Requests\Pos\VoidOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\Orders\PaymentService;
use App\Services\Orders\VoidService;
use Illuminate\Http\JsonResponse;

class OrderActionController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly VoidService $voids,
    ) {}

    public function discount(OrderDiscountRequest $request, Order $order): OrderResource
    {
        $this->orders->setOrderDiscount($order, $request->user(), $request->validated('discount_type'), $request->validated('discount_value'), $request->validated('pin'));

        return OrderResource::make($order->refresh());
    }

    public function serviceStatus(ServiceStatusRequest $request, Order $order): OrderResource
    {
        return OrderResource::make($this->orders->setServiceStatus($order, $request->user(), ServiceStatus::from($request->validated('service_status'))));
    }

    public function pay(PayRequest $request, Order $order): OrderResource
    {
        return OrderResource::make($this->payments->pay($order, $request->user(), $request->validated()));
    }

    /**
     * Walk-in cart → paid order in one request.
     */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $order = $this->payments->checkout(
            $request->user(),
            $request->validated('lines'),
            $request->safe()->only(['customer_id', 'customer_motorcycle_id', 'notes', 'discount_type', 'discount_value']),
            $request->safe()->only(['payment_method_id', 'amount_tendered', 'reference_no']),
            $request->validated('pin'),
        );

        return OrderResource::make($order)->response()->setStatusCode(201);
    }

    public function void(VoidOrderRequest $request, Order $order): OrderResource
    {
        return OrderResource::make($this->voids->void($order, $request->user(), $request->validated('reason'), $request->validated('pin')));
    }
}
