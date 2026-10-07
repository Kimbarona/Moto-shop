<?php

namespace App\Http\Controllers\Pos;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StoreCustomerRequest;
use App\Http\Requests\Pos\StoreMotorcycleRequest;
use App\Models\Customer;
use App\Models\CustomerMotorcycle;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /**
     * Search by name, mobile or plate no.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim($request->string('q')->toString());
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $customers = Customer::query()
            ->with('motorcycles.motorcycleModel.make')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', $like)
                ->orWhere('mobile', 'like', $like)
                ->orWhereHas('motorcycles', fn ($m) => $m->where('plate_no', 'like', $like))))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($customers->map(fn (Customer $customer) => $this->customerPayload($customer)));
    }

    /**
     * Quick-add a customer (and optionally their motorcycle) from the counter.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = DB::transaction(function () use ($request) {
            $customer = Customer::create($request->safe()->only(['name', 'mobile', 'address']));
            $bike = $request->validated('motorcycle');

            if (is_array($bike) && (filled($bike['plate_no'] ?? null) || filled($bike['motorcycle_model_id'] ?? null))) {
                $customer->motorcycles()->create($bike);
            }

            return $customer;
        });

        return response()->json($this->customerPayload($customer->load('motorcycles.motorcycleModel.make')), 201);
    }

    public function storeMotorcycle(StoreMotorcycleRequest $request, Customer $customer): JsonResponse
    {
        $customer->motorcycles()->create($request->validated());

        return response()->json($this->customerPayload($customer->load('motorcycles.motorcycleModel.make')), 201);
    }

    /**
     * Customer profile with service history per motorcycle.
     */
    public function show(Customer $customer): Response
    {
        $customer->load('motorcycles.motorcycleModel.make');

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', OrderStatus::Voided)
            ->with(['items', 'mechanics'])
            ->latest('created_at')
            ->get();

        $history = fn (?int $bikeId) => $orders->where('customer_motorcycle_id', $bikeId)->values()->map(fn (Order $order) => [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'slip_no' => $order->slip_no,
            'type' => $order->type->value,
            'status' => $order->status->value,
            'date' => ($order->paid_at ?? $order->created_at)?->toIso8601String(),
            'odometer' => $order->odometer_in,
            'complaint' => $order->complaint,
            'total' => (float) $order->total,
            'mechanics' => $order->mechanics->pluck('name')->values(),
            'items' => $order->items->map(fn (OrderItem $item) => [
                'description' => $item->description,
                'type' => $item->line_type->value,
                'qty' => (float) $item->qty,
            ])->values(),
        ]);

        return Inertia::render('customers/show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'address' => $customer->address,
                'notes' => $customer->notes,
                'since' => $customer->created_at?->toIso8601String(),
                'total_spent' => (float) $orders->where('status', OrderStatus::Paid)->sum('total'),
            ],
            'motorcycles' => $customer->motorcycles->map(fn (CustomerMotorcycle $bike) => [
                'id' => $bike->id,
                'plate_no' => $bike->plate_no,
                'model' => $bike->motorcycleModel?->full_name,
                'year' => $bike->year,
                'color' => $bike->color,
                'last_odometer' => $bike->last_odometer,
                'history' => $history($bike->id),
            ])->values(),
            'otherOrders' => $history(null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'motorcycles' => $customer->motorcycles->map(fn (CustomerMotorcycle $bike) => [
                'id' => $bike->id,
                'plate_no' => $bike->plate_no,
                'model' => $bike->motorcycleModel?->full_name,
                'model_id' => $bike->motorcycle_model_id,
                'color' => $bike->color,
                'last_odometer' => $bike->last_odometer,
            ])->values(),
        ];
    }
}
