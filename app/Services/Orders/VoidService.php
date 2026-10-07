<?php

namespace App\Services\Orders;

use App\Enums\ApprovalAction;
use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\Approvals\ManagerPinService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Void with a reason (cashiers need a manager PIN).
 *  - Pending: the reservation is released (reserved qty is computed from Pending orders).
 *  - Paid, same day only: stock comes back (void_reversal), payments are voided and
 *    commission is reversed. The slip number stays used — numbering never has gaps.
 *    An exchange sale releases its return credit so it can be used again.
 *  - Paid on an earlier day: blocked; use Return / Refund.
 */
class VoidService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly ManagerPinService $pins,
    ) {}

    public function void(Order $order, User $actor, string $reason, ?string $pin = null): Order
    {
        // State first, so "already voided" / "too late" read better than "unauthorized".
        $this->ensureVoidable($order->fresh());
        Gate::forUser($actor)->authorize('void', $order);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['void_reason' => 'Give a reason for voiding.']);
        }

        // PIN first, outside the transaction (see ManagerPinService).
        if ($this->pins->requiresPin($actor)) {
            if (blank($pin)) {
                throw ValidationException::withMessages(['pin' => 'Manager PIN required to void an order.']);
            }
        }

        $this->pins->authorize($actor, ApprovalAction::VoidOrder, $pin, $order, [
            'order_no' => $order->order_no,
            'slip_no' => $order->slip_no,
            'status' => $order->status->value,
            'reason' => $reason,
        ]);

        return DB::transaction(function () use ($order, $actor, $reason) {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->ensureVoidable($order);
            $wasPaid = $order->isPaid();

            if ($wasPaid) {
                $items = $order->items()->get();
                $parts = $items->filter(fn (OrderItem $item) => $item->isPart());

                $this->stock->lockProducts($parts->pluck('product_id'));

                foreach ($parts as $item) {
                    $this->stock->move(
                        $item->product_id,
                        MovementType::VoidReversal,
                        (float) $item->qty,
                        (float) $item->unit_cost,
                        $order,
                        "Void {$order->slip_no}: {$reason}",
                        $actor,
                    );
                }

                foreach ($items->filter(fn (OrderItem $item) => (float) $item->commission_amount != 0.0) as $item) {
                    $item->update(['commission_amount' => 0]);
                }

                $order->payments()->whereNull('voided_at')->update(['voided_at' => now(), 'voided_by' => $actor->id]);

                // An exchange sale gives its return credit back (usable on a new sale),
                // and any difference paid out to the customer is taken back.
                if ($order->exchange_return_id) {
                    SalesReturn::query()->lockForUpdate()->whereKey($order->exchange_return_id)
                        ->update(['refund_amount' => 0, 'refund_method_id' => null]);
                }
            }

            $order->forceFill([
                'status' => OrderStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ])->save();

            activity('Order')->causedBy($actor)->performedOn($order)->event('voided')
                ->withProperties(['was_paid' => $wasPaid, 'slip_no' => $order->slip_no, 'reason' => $reason, 'total' => (float) $order->total])
                ->log("Order {$order->order_no} voided");

            return $order;
        });
    }

    private function ensureVoidable(Order $order): void
    {
        if ($order->isVoided()) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_no} is already voided."]);
        }

        if (! $order->isPaid()) {
            return;
        }

        if (! $order->paid_at?->isSameDay(now())) {
            throw ValidationException::withMessages([
                'order' => 'Paid orders can only be voided on the day of payment. Use Return / Refund instead.',
            ]);
        }

        if ($order->refund_status !== RefundStatus::None) {
            throw ValidationException::withMessages([
                'order' => 'This order already has a return. Use Return / Refund for further changes.',
            ]);
        }
    }
}
