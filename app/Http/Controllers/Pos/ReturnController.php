<?php

namespace App\Http\Controllers\Pos;

use App\Enums\Disposition;
use App\Enums\RefundStatus;
use App\Enums\ReturnReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StoreReturnRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesReturn;
use App\Services\Orders\ReturnService;
use App\Support\PosCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /returns/new: find a paid order, pick lines and qty, set where parts go, refund or
 * exchange. Never sends cost (cashiers use this screen).
 */
class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returns) {}

    public function create(Request $request): Response
    {
        $order = null;

        if ($request->filled('order')) {
            $found = Order::find($request->integer('order'));

            if ($found && $found->isPaid()) {
                $order = static::orderPayload($found);
            }
        }

        $catalog = PosCatalog::props($request->user());

        return Inertia::render('returns/new', [
            'order' => $order,
            'reasons' => collect(ReturnReason::cases())->map(fn (ReturnReason $r) => ['value' => $r->value, 'label' => $r->getLabel()])->values(),
            'dispositions' => collect(Disposition::cases())->map(fn (Disposition $d) => ['value' => $d->value, 'label' => $d->getLabel()])->values(),
            'paymentMethods' => $catalog['paymentMethods'],
            'openOrdersCount' => $catalog['openOrdersCount'],
            'recent' => SalesReturn::query()
                ->with(['order.customer', 'exchangeOrder'])
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (SalesReturn $return) => static::returnPayload($return))
                ->values(),
        ]);
    }

    /**
     * Paid orders that still have something returnable, by slip no., order no.,
     * customer name / mobile or plate no.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim($request->string('q')->toString());
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $plate = '%'.str_replace(['%', '_', ' '], ['\%', '\_', ''], strtoupper($term)).'%';

        $orders = Order::query()
            ->paid()
            ->where('refund_status', '!=', RefundStatus::Full)
            ->with(['customer', 'motorcycle.motorcycleModel.make'])
            ->withCount('items')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('slip_no', 'like', $like)
                ->orWhere('order_no', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('mobile', 'like', $like))
                ->orWhereHas('motorcycle', fn ($m) => $m->whereRaw("REPLACE(plate_no, ' ', '') like ?", [$plate]))))
            ->latest('paid_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'slip_no' => $order->slip_no,
                'order_no' => $order->order_no,
                'type' => $order->type->value,
                'paid_at' => $order->paid_at?->toIso8601String(),
                'customer' => $order->customer?->name,
                'plate_no' => $order->motorcycle?->plate_no,
                'model' => $order->motorcycle?->motorcycleModel?->full_name,
                'items_count' => $order->items_count,
                'total' => (float) $order->total,
                'refund_status' => $order->refund_status->value,
            ]);

        return response()->json($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->isPaid(), 404, 'Only paid orders can be returned.');

        return response()->json(static::orderPayload($order));
    }

    public function store(StoreReturnRequest $request): JsonResponse
    {
        $return = $this->returns->process(
            Order::findOrFail($request->validated('order_id')),
            $request->user(),
            $request->safe()->except(['order_id', 'pin']),
            $request->validated('pin'),
        );

        return response()->json(static::returnPayload($return->load(['order.customer', 'exchangeOrder'])), 201);
    }

    /**
     * @return array<string, mixed>
     */
    public static function orderPayload(Order $order): array
    {
        $order->loadMissing(['items.product.unit', 'items.returnItems', 'customer', 'motorcycle.motorcycleModel.make', 'cashier', 'returns']);

        return [
            'id' => $order->id,
            'slip_no' => $order->slip_no,
            'order_no' => $order->order_no,
            'type' => $order->type->value,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cashier' => $order->cashier?->name,
            'customer' => $order->customer ? ['id' => $order->customer->id, 'name' => $order->customer->name, 'mobile' => $order->customer->mobile] : null,
            'motorcycle' => $order->motorcycle ? [
                'id' => $order->motorcycle->id,
                'plate_no' => $order->motorcycle->plate_no,
                'model' => $order->motorcycle->motorcycleModel?->full_name,
            ] : null,
            'total' => (float) $order->total,
            'refund_status' => $order->refund_status->value,
            'items' => $order->items->map(function (OrderItem $item) {
                $returnedAmount = round((float) $item->returnItems->sum('amount'), 2);

                return [
                    'id' => $item->id,
                    'line_type' => $item->line_type->value,
                    'description' => $item->description,
                    'sku' => $item->product?->sku,
                    'unit' => $item->product?->unit?->abbreviation,
                    'allows_decimal' => (bool) $item->product?->unit?->allows_decimal,
                    'qty' => (float) $item->qty,
                    'qty_returned' => (float) $item->qty_returned,
                    'returnable' => max(0.0, $item->returnableQty()),
                    'net' => $item->netAmount(),
                    'returned_amount' => $returnedAmount,
                    // Per-unit price actually paid (after line and order discounts).
                    'unit_net' => (float) $item->qty > 0 ? round($item->netAmount() / (float) $item->qty, 4) : 0,
                    // What is left to refund on this line (the last return takes exactly this).
                    'remaining_amount' => round($item->netAmount() - $returnedAmount, 2),
                ];
            })->values()->all(),
            'returns' => $order->returns->map(fn (SalesReturn $return) => [
                'id' => $return->id,
                'return_no' => $return->return_no,
                'date' => $return->date?->toIso8601String(),
                'resolution' => $return->resolution->value,
                'return_total' => (float) $return->return_total,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function returnPayload(SalesReturn $return): array
    {
        $exchangeOrder = $return->exchangeOrder;

        return [
            'id' => $return->id,
            'return_no' => $return->return_no,
            'date' => $return->date?->toIso8601String(),
            'order_id' => $return->order_id,
            'slip_no' => $return->order?->slip_no,
            'customer' => $return->order?->customer?->name,
            'reason' => $return->reason->getLabel(),
            'resolution' => $return->resolution->value,
            'return_total' => (float) $return->return_total,
            'refund_amount' => (float) $return->refund_amount,
            'exchange_order' => $exchangeOrder ? ['id' => $exchangeOrder->id, 'slip_no' => $exchangeOrder->slip_no] : null,
            'credit_available' => $return->isExchange() && ! $exchangeOrder,
        ];
    }
}
