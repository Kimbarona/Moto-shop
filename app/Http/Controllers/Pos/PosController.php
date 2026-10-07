<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Support\PosCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    /**
     * Counter screen. ?order=ID resumes a Pending order; ?exchange=ID starts a sale that
     * uses an exchange return's credit.
     */
    public function index(Request $request): Response
    {
        $order = null;

        if ($request->filled('order')) {
            $found = Order::find($request->integer('order'));

            if ($found && $found->isPending() && $request->user()->can('view', $found)) {
                $order = OrderResource::make($found)->resolve($request);
            }
        }

        return Inertia::render('pos/index', [
            ...PosCatalog::props($request->user()),
            'mode' => 'pos',
            'order' => $order,
            'exchange' => $order === null && $request->filled('exchange') ? $this->exchange($request->integer('exchange')) : null,
        ]);
    }

    /**
     * An exchange return whose credit is still unused, for a new walk-in sale.
     *
     * @return array<string, mixed>|null
     */
    private function exchange(int $returnId): ?array
    {
        $return = SalesReturn::query()->unusedExchange()->with('order.customer', 'order.motorcycle.motorcycleModel')->find($returnId);

        if (! $return) {
            return null;
        }

        $customer = $return->order?->customer;
        $bike = $return->order?->motorcycle;

        return [
            'id' => $return->id,
            'return_no' => $return->return_no,
            'slip_no' => $return->order?->slip_no,
            'credit' => (float) $return->return_total,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'mobile' => $customer->mobile] : null,
            'motorcycle' => $bike ? ['id' => $bike->id, 'plate_no' => $bike->plate_no, 'model' => $bike->motorcycleModel?->full_name, 'model_id' => $bike->motorcycle_model_id] : null,
        ];
    }

    /**
     * Job sheet: the same screen, used from the Service Board (mechanics edit parts and
     * labor on their own jobs; no payment, no discounts).
     */
    public function job(Request $request, Order $order): Response
    {
        $this->authorize('view', $order);

        return Inertia::render('pos/index', [
            ...PosCatalog::props($request->user()),
            'mode' => 'job',
            'order' => OrderResource::make($order)->resolve($request),
        ]);
    }
}
