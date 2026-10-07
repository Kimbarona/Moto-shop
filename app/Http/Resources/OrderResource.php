<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Order for the POS / Service Board. Cost and profit are only included for roles that
 * may see cost (admins); commission only for admins and the mechanic it belongs to.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $viewCost = $user->can('view-cost');
        $ownMechanicId = $user->isMechanic() ? $user->mechanic?->id : null;

        $this->resource->loadMissing([
            'items.product.unit', 'items.package', 'items.mechanic', 'customer', 'motorcycle.motorcycleModel.make',
            'mechanics', 'payments.method', 'cashier', 'creator', 'voider',
        ]);

        $items = $this->items;
        $reserved = $this->isPending() ? $this->availableForLines($items) : [];

        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'slip_no' => $this->slip_no,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'service_status' => $this->service_status?->value,
            'refund_status' => $this->refund_status->value,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'mobile' => $this->customer->mobile,
            ] : null,
            'motorcycle' => $this->motorcycle ? [
                'id' => $this->motorcycle->id,
                'plate_no' => $this->motorcycle->plate_no,
                'model' => $this->motorcycle->motorcycleModel?->full_name,
                'model_id' => $this->motorcycle->motorcycle_model_id,
                'color' => $this->motorcycle->color,
            ] : null,
            'odometer_in' => $this->odometer_in,
            'complaint' => $this->complaint,
            'notes' => $this->notes,
            'mechanics' => $this->mechanics->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'items' => $items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'line_type' => $item->line_type->value,
                'product_id' => $item->product_id,
                'service_id' => $item->service_id,
                'package' => $item->package?->name,
                'description' => $item->description,
                'sku' => $item->product?->sku,
                'unit' => $item->product?->unit?->abbreviation,
                'allows_decimal' => (bool) $item->product?->unit?->allows_decimal,
                'qty' => (float) $item->qty,
                'qty_returned' => (float) $item->qty_returned,
                'original_price' => (float) $item->original_price,
                'unit_price' => (float) $item->unit_price,
                'discount_type' => $item->discount_type?->value,
                'discount_value' => (float) $item->discount_value,
                'discount' => (float) $item->discount,
                'line_total' => (float) $item->line_total,
                'discount_share' => (float) $item->discount_share,
                'net' => $item->netAmount(),
                'max_qty' => $reserved[$item->id] ?? null,
                'mechanic_id' => $item->mechanic_id,
                'mechanic' => $item->mechanic?->name,
                // Conditional keys are built explicitly: when() is not applied inside
                // nested collections, and these must never reach the wrong role.
                ...($viewCost || ($ownMechanicId !== null && $ownMechanicId === $item->mechanic_id)
                    ? ['commission' => (float) $item->commission_amount]
                    : []),
                ...($viewCost ? ['unit_cost' => $item->unit_cost !== null ? (float) $item->unit_cost : null] : []),
            ])->values()->all(),
            'subtotal' => (float) $this->subtotal,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => (float) $this->discount_value,
            'discount_total' => (float) $this->discount_total,
            'vatable' => (float) $this->vatable,
            'vat_amount' => (float) $this->vat_amount,
            'total' => (float) $this->total,
            'return_credit' => (float) $this->return_credit,
            'amount_due' => $this->amountDue(),
            'profit' => $this->when($viewCost && $this->isPaid(), fn () => round($items->sum(
                fn (OrderItem $item) => $item->netAmount() - ($item->isPart() ? (float) $item->unit_cost * (float) $item->qty : 0),
            ), 2)),
            'payments' => $this->payments->map(fn (Payment $p) => [
                'id' => $p->id,
                'method' => $p->method?->name,
                'method_type' => $p->method?->type->value,
                'amount' => (float) $p->amount,
                'amount_tendered' => $p->amount_tendered !== null ? (float) $p->amount_tendered : null,
                'change_due' => (float) $p->change_due,
                'reference_no' => $p->reference_no,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'voided' => $p->voided_at !== null,
            ])->values(),
            'cashier' => $this->cashier?->name,
            'created_by' => $this->creator?->name,
            'voided_by' => $this->voider?->name,
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'done_at' => $this->done_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'can' => [
                'editItems' => $user->can('editItems', $this->resource),
                'discount' => $user->can('discount', $this->resource),
                'pay' => $user->can('pay', $this->resource),
                'void' => $user->can('void', $this->resource),
                'serviceStatus' => $user->can('updateServiceStatus', $this->resource),
            ],
        ];
    }

    /**
     * Highest qty each part line may be raised to (its own qty + what is still available).
     *
     * @param  iterable<OrderItem>  $items
     * @return array<int, float>
     */
    private function availableForLines(iterable $items): array
    {
        $max = [];

        foreach ($items as $item) {
            if ($item->isPart() && $item->product) {
                $max[$item->id] = round((float) $item->qty + max(0, $item->product->availableQty()), 2);
            }
        }

        return $max;
    }
}
