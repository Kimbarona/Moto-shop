<?php

namespace App\Services\Orders;

use App\Enums\ApprovalAction;
use App\Enums\Disposition;
use App\Enums\MovementType;
use App\Enums\PaymentMethodType;
use App\Enums\RefundStatus;
use App\Enums\ReturnReason;
use App\Enums\ReturnResolution;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\ReturnItem;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\Approvals\ManagerPinService;
use App\Services\Numbering\SequenceService;
use App\Services\Stock\DefectiveStockService;
use App\Services\Stock\StockService;
use App\Settings\PosSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Returns against a PAID order (cashiers need a manager PIN). In ONE transaction:
 *  - parts back_to_stock → return_to_stock movement (+) at the line's original unit_cost
 *  - parts defective     → defective bucket (never back on the shelf, no movement)
 *  - labor               → amount only; the mechanic's commission is reversed pro rata
 *  - order_items.qty_returned and orders.refund_status (partial / full) are updated
 *  - refund: the money goes back by the chosen method; exchange: the amount becomes
 *    credit for a new walk-in sale (PaymentService::checkout with exchange_return_id)
 *
 * Each line's amount is what the customer actually paid for that qty (after line and
 * order discounts). The last return of a line takes whatever is left, so returning a
 * line in parts never refunds more (or less) than it was sold for.
 */
class ReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DefectiveStockService $defective,
        private readonly CommissionService $commissions,
        private readonly SequenceService $sequences,
        private readonly ManagerPinService $pins,
        private readonly PosSettings $pos,
    ) {}

    /**
     * @param  array{reason: string, remarks?: string|null, resolution: string, refund_method_id?: int|null, refund_reference?: string|null, items: list<array{order_item_id: int, qty: float|string, disposition?: string|null}>}  $data
     */
    public function process(Order $order, User $actor, array $data, ?string $pin = null): SalesReturn
    {
        $order = $order->fresh();
        $this->ensureReturnable($order);
        Gate::forUser($actor)->authorize('return', $order);

        $reason = ReturnReason::tryFrom((string) ($data['reason'] ?? ''))
            ?? throw ValidationException::withMessages(['reason' => 'Pick a reason for the return.']);
        $resolution = ReturnResolution::tryFrom((string) ($data['resolution'] ?? ''))
            ?? throw ValidationException::withMessages(['resolution' => 'Choose refund or exchange.']);
        $lines = $this->normalizeLines($data['items'] ?? []);
        $method = $resolution === ReturnResolution::Refund ? $this->refundMethod($data) : null;
        $reference = $method ? $this->reference($method, $data['refund_reference'] ?? null) : null;

        // Check the lines before asking for a PIN, so the manager isn't bothered for a typo.
        $this->checkLines($order, $lines);

        if ($this->pins->requiresPin($actor) && blank($pin)) {
            throw ValidationException::withMessages(['pin' => 'Manager PIN required to approve a return.']);
        }

        // PIN first, outside the transaction (see ManagerPinService).
        $approval = $this->pins->authorize($actor, ApprovalAction::Return, $pin, $order, [
            'order_no' => $order->order_no,
            'slip_no' => $order->slip_no,
            'resolution' => $resolution->value,
            'reason' => $reason->value,
        ]);

        return DB::transaction(function () use ($order, $actor, $data, $reason, $resolution, $lines, $method, $reference, $approval) {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->ensureReturnable($order);

            /** @var Collection<int, OrderItem> $items */
            $items = $order->items()->lockForUpdate()->get()->keyBy('id');
            $this->checkLines($order, $lines, $items);

            $partIds = collect($lines)->map(fn ($line) => $items[$line['order_item_id']])
                ->filter(fn (OrderItem $item) => $item->isPart())
                ->pluck('product_id');
            $products = $this->stock->lockProducts($partIds);

            $return = SalesReturn::create([
                'return_no' => $this->sequences->next('return'),
                'order_id' => $order->id,
                'date' => now(),
                'reason' => $reason,
                'remarks' => filled($data['remarks'] ?? null) ? trim((string) $data['remarks']) : null,
                'resolution' => $resolution,
                'refund_method_id' => $method?->id,
                'refund_reference' => $reference,
                'approved_by' => $approval->approver_id,
                'processed_by' => $actor->id,
            ]);

            $total = 0.0;

            foreach ($lines as $line) {
                /** @var OrderItem $item */
                $item = $items[$line['order_item_id']];
                $qty = $line['qty'];
                $isLast = abs($item->returnableQty() - $qty) < 0.005;
                $previous = $item->returnItems()->get();

                $amount = $isLast
                    ? round($item->netAmount() - (float) $previous->sum('amount'), 2)
                    : round($item->netAmount() * $qty / (float) $item->qty, 2);

                $commission = $item->isLabor()
                    ? ($isLast
                        ? round((float) $item->commission_amount - (float) $previous->sum('commission_reversed'), 2)
                        : $this->commissions->reversalFor($item, $qty))
                    : 0.0;

                $returnItem = ReturnItem::create([
                    'return_id' => $return->id,
                    'order_item_id' => $item->id,
                    'qty' => $qty,
                    'disposition' => $item->isPart() ? $line['disposition'] : null,
                    'unit_cost' => $item->isPart() ? (float) $item->unit_cost : 0,
                    'amount' => max(0.0, $amount),
                    'commission_reversed' => max(0.0, $commission),
                ]);

                if ($item->isPart()) {
                    if ($line['disposition'] === Disposition::BackToStock) {
                        $this->stock->move(
                            $products[$item->product_id],
                            MovementType::ReturnToStock,
                            $qty,
                            (float) $item->unit_cost,
                            $return,
                            "Return {$return->return_no} (slip {$order->slip_no})",
                            $actor,
                        );
                    } else {
                        $this->defective->fromReturn(
                            $products[$item->product_id],
                            $qty,
                            (float) $item->unit_cost,
                            $returnItem->id,
                            "Customer return {$return->return_no} (slip {$order->slip_no}): {$reason->getLabel()}",
                        );
                    }
                }

                $item->qty_returned = round((float) $item->qty_returned + $qty, 2);
                $item->save();

                $total += (float) $returnItem->amount;
            }

            $total = round($total, 2);

            $return->forceFill([
                'return_total' => $total,
                'refund_amount' => $resolution === ReturnResolution::Refund ? $total : 0,
            ])->save();

            $fullyReturned = $items->every(fn (OrderItem $item) => $item->returnableQty() <= 0);
            $order->forceFill(['refund_status' => $fullyReturned ? RefundStatus::Full : RefundStatus::Partial])->save();

            activity('Order')->causedBy($actor)->performedOn($order)->event('returned')
                ->withProperties([
                    'return_no' => $return->return_no,
                    'resolution' => $resolution->value,
                    'reason' => $reason->value,
                    'return_total' => $total,
                    'refund_method' => $method?->name,
                    'approved_by' => $approval->approver_id,
                ])
                ->log("Return {$return->return_no} on slip {$order->slip_no}");

            return $return->load('items');
        });
    }

    /**
     * Lock an unused exchange return for a sale (inside the sale's transaction).
     */
    public function lockExchangeCredit(int $returnId): SalesReturn
    {
        /** @var SalesReturn|null $return */
        $return = SalesReturn::query()->lockForUpdate()->find($returnId);

        if (! $return || ! $return->isExchange()) {
            throw ValidationException::withMessages(['exchange_return_id' => 'That return is not an exchange.']);
        }

        if ($return->exchangeOrder()->exists()) {
            throw ValidationException::withMessages([
                'exchange_return_id' => "The credit from {$return->return_no} was already used.",
            ]);
        }

        return $return;
    }

    private function ensureReturnable(?Order $order): void
    {
        if (! $order || ! $order->isPaid()) {
            throw ValidationException::withMessages(['order' => 'Only paid orders can be returned.']);
        }

        if ($order->refund_status === RefundStatus::Full) {
            throw ValidationException::withMessages(['order' => "Everything on slip {$order->slip_no} was already returned."]);
        }
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return list<array{order_item_id: int, qty: float, disposition: Disposition|null}>
     */
    private function normalizeLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $index => $line) {
            $qty = round((float) ($line['qty'] ?? 0), 2);

            if ($qty <= 0) {
                continue; // lines left at 0 are simply not returned
            }

            $id = (int) ($line['order_item_id'] ?? 0);

            if (isset($normalized[$id])) {
                throw ValidationException::withMessages(["items.{$index}.order_item_id" => 'Each line can only be listed once.']);
            }

            $normalized[$id] = [
                'order_item_id' => $id,
                'qty' => $qty,
                'disposition' => Disposition::tryFrom((string) ($line['disposition'] ?? '')),
                'index' => $index,
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages(['items' => 'Pick at least one item and quantity to return.']);
        }

        return array_values($normalized);
    }

    /**
     * @param  list<array{order_item_id: int, qty: float, disposition: Disposition|null, index?: int}>  $lines
     * @param  Collection<int, OrderItem>|null  $items  locked rows, when inside the transaction
     */
    private function checkLines(Order $order, array $lines, ?Collection $items = null): void
    {
        $items ??= $order->items()->get()->keyBy('id');

        foreach ($lines as $line) {
            $key = 'items.'.($line['index'] ?? 0);
            /** @var OrderItem|null $item */
            $item = $items[$line['order_item_id']] ?? null;

            if (! $item) {
                throw ValidationException::withMessages(["{$key}.order_item_id" => 'That item is not on this order.']);
            }

            $left = $item->returnableQty();

            if ($line['qty'] > $left + 0.0001) {
                throw ValidationException::withMessages([
                    "{$key}.qty" => $left <= 0
                        ? "{$item->description} was already returned in full."
                        : sprintf('Only %s of %s can still be returned.', rtrim(rtrim(number_format($left, 2), '0'), '.'), $item->description),
                ]);
            }

            if ($item->isPart() && $line['disposition'] === null) {
                throw ValidationException::withMessages(["{$key}.disposition" => "Say where {$item->description} goes: back to stock or defective."]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function refundMethod(array $data): PaymentMethod
    {
        return PaymentMethod::query()->active()->find($data['refund_method_id'] ?? 0)
            ?? throw ValidationException::withMessages(['refund_method_id' => 'Pick how the money is given back.']);
    }

    private function reference(PaymentMethod $method, mixed $reference): ?string
    {
        $reference = filled($reference) ? trim((string) $reference) : null;
        $needs = $method->requires_reference
            || ($this->pos->require_reference_for_non_cash && $method->type !== PaymentMethodType::Cash);

        if ($needs && $reference === null) {
            throw ValidationException::withMessages(['refund_reference' => "{$method->name} refunds need a reference no."]);
        }

        return $reference;
    }
}
