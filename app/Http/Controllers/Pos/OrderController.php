<?php

namespace App\Http\Controllers\Pos;

use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StoreOrderRequest;
use App\Http\Requests\Pos\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Services\Orders\OrderService;
use App\Services\Orders\PaymentService;
use App\Support\OrderTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {}

    /**
     * New Pending order: a service order, or a walk-in cart saved as Pending.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $lines = $request->validated('lines') ?? [];

        if ($lines !== []) {
            // Same rule as checkout: a discounted cart needs one manager PIN up front.
            $this->payments->approveCartDiscounts($user, $lines, [], $request->input('pin'));
        }

        $order = DB::transaction(function () use ($request, $user, $lines) {
            $order = $this->orders->create($user, OrderType::from($request->validated('type')), $request->safe()->except(['type', 'lines']));

            if ($lines !== []) {
                $this->payments->fillCart($order, $user, $lines);
            }

            return $order;
        });

        return OrderResource::make($order->refresh())->response()->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return OrderResource::make($order);
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        return OrderResource::make($this->orders->updateDetails($order, $request->user(), $request->validated()));
    }

    /**
     * Open (Pending) orders. Mechanics see their own jobs only.
     */
    public function open(Request $request): Response
    {
        $user = $request->user();

        $orders = Order::query()
            ->pending()
            ->with(['customer', 'motorcycle.motorcycleModel.make', 'mechanics'])
            ->withCount('items')
            ->when($user->isMechanic(), fn ($q) => $q->whereHas('mechanics', fn ($m) => $m->where('user_id', $user->id)))
            ->oldest()
            ->get()
            ->map(fn (Order $order) => static::card($order));

        return Inertia::render('orders/open', ['orders' => $orders]);
    }

    /**
     * Order detail: lines, payments, timeline, print / void / return.
     */
    public function page(Request $request, Order $order): Response
    {
        $this->authorize('view', $order);

        return Inertia::render('orders/show', [
            'order' => OrderResource::make($order)->resolve($request),
            'timeline' => OrderTimeline::for($order),
            'returns' => $order->returns()->with(['order.customer', 'exchangeOrder'])->get()
                ->map(fn (SalesReturn $return) => ReturnController::returnPayload($return))->values(),
        ]);
    }

    /**
     * Compact card used by Open Orders and the Service Board.
     *
     * @return array<string, mixed>
     */
    public static function card(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'type' => $order->type->value,
            'service_status' => $order->service_status?->value,
            'customer' => $order->customer?->name,
            'plate_no' => $order->motorcycle?->plate_no,
            'model' => $order->motorcycle?->motorcycleModel?->full_name,
            'complaint' => $order->complaint,
            'mechanics' => $order->mechanics->pluck('name')->values(),
            'items_count' => $order->items_count ?? $order->items()->count(),
            'total' => (float) $order->total,
            'created_at' => $order->created_at?->toIso8601String(),
            'done_at' => $order->done_at?->toIso8601String(),
        ];
    }
}
