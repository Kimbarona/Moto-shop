<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\AddItemRequest;
use App\Http\Requests\Pos\UpdateItemRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderService;
use Illuminate\Http\Request;

/**
 * Lines on a Pending order. Every response is the whole refreshed order.
 */
class OrderItemController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function store(AddItemRequest $request, Order $order): OrderResource
    {
        $user = $request->user();
        $qty = (float) ($request->validated('qty') ?? 1);

        match ($request->validated('type')) {
            'part' => $this->orders->addPart($order, $user, (int) $request->validated('product_id'), $qty),
            'labor' => $this->orders->addLabor($order, $user, (int) $request->validated('service_id'), $qty, $request->validated('mechanic_id')),
            'package' => $this->orders->addPackage($order, $user, (int) $request->validated('package_id')),
        };

        return OrderResource::make($order->refresh());
    }

    public function update(UpdateItemRequest $request, Order $order, OrderItem $item): OrderResource
    {
        $this->orders->updateLine($order, $item, $request->user(), $request->safe()->except('pin'), $request->validated('pin'));

        return OrderResource::make($order->refresh());
    }

    public function destroy(Request $request, Order $order, OrderItem $item): OrderResource
    {
        $this->orders->removeLine($order, $item, $request->user());

        return OrderResource::make($order->refresh());
    }
}
