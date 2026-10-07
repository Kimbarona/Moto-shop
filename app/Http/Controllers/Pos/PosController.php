<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Support\PosCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    /**
     * Counter screen. ?order=ID resumes a Pending order.
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
        ]);
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
