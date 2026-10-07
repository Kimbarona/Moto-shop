<?php

namespace App\Services\Orders;

use App\Enums\ApprovalAction;
use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethodType;
use App\Enums\ServiceStatus;
use App\Models\CustomerMotorcycle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Service;
use App\Models\User;
use App\Services\Numbering\SequenceService;
use App\Services\Stock\StockService;
use App\Settings\PosSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pending → Paid. In ONE transaction:
 *  1. lock the order, then its products (ascending id)
 *  2. deduct stock (a "sale" movement per part line) and snapshot unit_cost = avg_cost
 *  3. compute mechanic commission on labor lines
 *  4. record the payment and assign the next slip number (last, so a failure anywhere
 *     rolls the number back and slip numbers never have gaps)
 *
 * Exchange: checkout() may carry exchange_return_id. The return's value is applied as
 * credit (never more than the sale total); the customer pays the rest, or receives the
 * difference, which is recorded as the return's refund_amount.
 */
class PaymentService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PricingService $pricing,
        private readonly CommissionService $commissions,
        private readonly SequenceService $sequences,
        private readonly OrderService $orders,
        private readonly PosSettings $pos,
        private readonly ReturnService $returns,
    ) {}

    /**
     * @param  array{payment_method_id: int, amount_tendered?: float|string|null, reference_no?: string|null}  $payment
     */
    public function pay(Order $order, User $actor, array $payment): Order
    {
        return DB::transaction(fn () => $this->payLocked($order, $actor, $payment));
    }

    /**
     * Walk-in counter sale: create the order from the cart and pay it in one go.
     * One manager PIN covers every discount on the cart.
     * The return credit is always computed here from the return, never taken from input.
     *
     * @param  list<array<string, mixed>>  $lines  [{type: part|labor|package, product_id|service_id|package_id, qty?, unit_price?, discount_type?, discount_value?, mechanic_id?}]
     * @param  array{customer_id?: int|null, customer_motorcycle_id?: int|null, notes?: string|null, discount_type?: string|null, discount_value?: float|string|null, exchange_return_id?: int|null}  $attributes
     * @param  array{payment_method_id: int, amount_tendered?: float|string|null, reference_no?: string|null}  $payment
     */
    public function checkout(User $actor, array $lines, array $attributes, array $payment, ?string $pin = null): Order
    {
        Gate::forUser($actor)->authorize('create', Order::class);
        Gate::forUser($actor)->authorize('take-payment');

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'The cart is empty.']);
        }

        $this->approveCartDiscounts($actor, $lines, $attributes, $pin);

        return DB::transaction(function () use ($actor, $lines, $attributes, $payment) {
            // Exchange: lock the return first so its credit can only be used once.
            $exchange = filled($attributes['exchange_return_id'] ?? null)
                ? $this->returns->lockExchangeCredit((int) $attributes['exchange_return_id'])
                : null;

            $order = $this->orders->create($actor, OrderType::WalkIn, [
                'customer_id' => $attributes['customer_id'] ?? null,
                'customer_motorcycle_id' => $attributes['customer_motorcycle_id'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->fillCart($order, $actor, $lines);

            if (filled($attributes['discount_value'] ?? null) && (float) $attributes['discount_value'] > 0) {
                $this->orders->setOrderDiscount($order, $actor, $attributes['discount_type'] ?? null, $attributes['discount_value'], preApproved: true);
            }

            return $this->payLocked($order, $actor, $payment, $exchange);
        });
    }

    /**
     * Add cart lines to a new Pending order (shared by checkout and "save as pending").
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function fillCart(Order $order, User $actor, array $lines): void
    {
        foreach ($lines as $line) {
            $type = $line['type'] ?? null;

            if ($type === 'package') {
                $this->orders->addPackage($order, $actor, (int) $line['package_id']);

                continue;
            }

            $item = match ($type) {
                'part' => $this->orders->addPart($order, $actor, (int) $line['product_id'], (float) ($line['qty'] ?? 1)),
                'labor' => $this->orders->addLabor($order, $actor, (int) $line['service_id'], (float) ($line['qty'] ?? 1), isset($line['mechanic_id']) ? (int) $line['mechanic_id'] : null),
                default => throw ValidationException::withMessages(['lines' => 'Unknown line type.']),
            };

            $changes = collect($line)->only(['unit_price', 'discount_type', 'discount_value'])->filter(fn ($v) => $v !== null && $v !== '')->all();

            if ($changes !== []) {
                $this->orders->updateLine($order, $item, $actor, $changes, preApproved: true);
            }
        }
    }

    /**
     * Inside an open transaction.
     *
     * @param  array{payment_method_id: int, amount_tendered?: float|string|null, reference_no?: string|null}  $payment
     */
    private function payLocked(Order $order, User $actor, array $payment, ?SalesReturn $exchange = null): Order
    {
        /** @var Order $order */
        $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());

        if (! $order->isPending()) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_no} is already {$order->status->getLabel()}."]);
        }

        Gate::forUser($actor)->authorize('pay', $order);

        $order = $this->pricing->recalculate($order);
        $items = $order->items;

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['order' => 'Add at least one item before payment.']);
        }

        if ($order->type === OrderType::WalkIn && ! $this->pos->allow_walk_in_without_customer && ! $order->customer_id) {
            throw ValidationException::withMessages(['customer_id' => 'Pick a customer for this sale (walk-in without a customer is turned off in Settings).']);
        }

        // Exchange credit covers the sale up to its total; any excess is paid out below.
        $credit = $exchange ? (float) $exchange->return_total : 0.0;

        if ($exchange) {
            $order->exchange_return_id = $exchange->id;
            $order->return_credit = min($credit, (float) $order->total);
        }

        $due = $order->amountDue();
        [$method, $tendered, $change, $reference] = $this->validatePayment($payment, $due);

        // 1–2. Deduct stock and snapshot unit cost.
        $partLines = $items->filter(fn (OrderItem $item) => $item->isPart());
        $products = $this->stock->lockProducts($partLines->pluck('product_id'));
        $paidAt = now();

        foreach ($partLines as $item) {
            /** @var Product $product */
            $product = $products[$item->product_id];
            $item->unit_cost = $product->avg_cost;

            $this->stock->move($product, MovementType::Sale, -(float) $item->qty, (float) $product->avg_cost, $order, "Sale {$order->order_no}", $actor);
        }

        // 3. Commission on labor lines (default to the order's first mechanic).
        $fallbackMechanic = $order->mechanics()->orderBy('mechanics.id')->value('mechanics.id');

        foreach ($items->filter(fn (OrderItem $item) => $item->isLabor()) as $item) {
            $item->mechanic_id ??= $fallbackMechanic;
            $item->setRelation('service', $item->service_id ? Service::withTrashed()->find($item->service_id) : null);
            $item->commission_amount = $this->commissions->forLine($item);
        }

        foreach ($items as $item) {
            $item->save();
        }

        // 4. Payment + slip number.
        if ($due > 0) {
            Payment::create([
                'order_id' => $order->id,
                'payment_method_id' => $method->id,
                'amount' => $due,
                'amount_tendered' => $tendered,
                'change_due' => $change,
                'reference_no' => $reference,
                'received_by' => $actor->id,
                'paid_at' => $paidAt,
            ]);
        }

        $order->forceFill([
            'status' => OrderStatus::Paid,
            'paid_at' => $paidAt,
            'cashier_id' => $actor->id,
            'slip_no' => $this->sequences->next('slip'),
            'service_status' => $order->isService() ? ServiceStatus::Done : null,
            'done_at' => $order->isService() ? ($order->done_at ?? $paidAt) : null,
        ])->save();

        if ($exchange) {
            $excess = round($credit - (float) $order->return_credit, 2);

            // The customer receives the difference (refund_amount = money paid out).
            $exchange->forceFill([
                'refund_amount' => max(0.0, $excess),
                'refund_method_id' => $excess > 0 ? $this->payoutMethod($payment)?->id : null,
            ])->save();
        }

        if ($order->customer_motorcycle_id && $order->odometer_in) {
            CustomerMotorcycle::whereKey($order->customer_motorcycle_id)
                ->where(fn ($q) => $q->whereNull('last_odometer')->orWhere('last_odometer', '<', $order->odometer_in))
                ->update(['last_odometer' => $order->odometer_in]);
        }

        activity('Order')->causedBy($actor)->performedOn($order)->event('paid')
            ->withProperties([
                'slip_no' => $order->slip_no,
                'total' => (float) $order->total,
                'amount_due' => $due,
                'method' => $method?->name,
                'reference_no' => $reference,
                'exchange_return' => $exchange?->return_no,
                'return_credit' => $exchange ? (float) $order->return_credit : null,
            ])
            ->log("Order {$order->order_no} paid, slip {$order->slip_no}");

        return $order->refresh()->load(['items', 'payments']);
    }

    /**
     * How an exchange difference is given back: the method picked, else cash.
     *
     * @param  array<string, mixed>  $payment
     */
    private function payoutMethod(array $payment): ?PaymentMethod
    {
        return PaymentMethod::query()->active()->find($payment['payment_method_id'] ?? 0)
            ?? PaymentMethod::query()->active()->where('type', PaymentMethodType::Cash)->orderBy('sort_order')->first();
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array{0: PaymentMethod|null, 1: float|null, 2: float, 3: string|null}
     */
    private function validatePayment(array $payment, float $due): array
    {
        if ($due <= 0) {
            return [null, null, 0.0, null];
        }

        $method = PaymentMethod::query()->active()->find($payment['payment_method_id'] ?? 0)
            ?? throw ValidationException::withMessages(['payment_method_id' => 'Pick a payment method.']);

        $reference = filled($payment['reference_no'] ?? null) ? trim((string) $payment['reference_no']) : null;
        $needsReference = $method->requires_reference
            || ($this->pos->require_reference_for_non_cash && $method->type !== PaymentMethodType::Cash);

        if ($needsReference && $reference === null) {
            throw ValidationException::withMessages(['reference_no' => "{$method->name} needs a reference no."]);
        }

        if ($method->type === PaymentMethodType::Cash) {
            $tendered = round((float) ($payment['amount_tendered'] ?? $due), 2);

            if ($tendered < $due) {
                throw ValidationException::withMessages([
                    'amount_tendered' => sprintf('Amount tendered (₱%s) is less than the amount due (₱%s).', number_format($tendered, 2), number_format($due, 2)),
                ]);
            }

            return [$method, $tendered, round($tendered - $due, 2), $reference];
        }

        return [$method, $due, 0.0, $reference];
    }

    /**
     * Check the whole cart's discounts up front so a single manager PIN covers them.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attributes
     */
    public function approveCartDiscounts(User $actor, array $lines, array $attributes, ?string $pin): void
    {
        $worst = 0.0;
        $gross = 0.0;
        $net = 0.0;
        $priceLowered = false;

        foreach ($lines as $line) {
            if (($line['type'] ?? null) === 'package') {
                continue;
            }

            $original = $line['type'] === 'part'
                ? (float) Product::whereKey($line['product_id'] ?? 0)->value('srp')
                : (float) Service::whereKey($line['service_id'] ?? 0)->value('labor_fee');

            $item = new OrderItem([
                'qty' => $line['qty'] ?? 1,
                'original_price' => $original,
                'unit_price' => $line['unit_price'] ?? $original,
            ]);
            [$type, $value] = $this->orders->normalizeDiscount($line['discount_type'] ?? null, $line['discount_value'] ?? null);
            $item->discount_type = $type;
            $item->discount_value = $value;

            $priceLowered = $priceLowered || (float) $item->unit_price < $original;
            $worst = max($worst, $this->pricing->lineReductionPercent($item));
            $gross += $original * (float) $item->qty;
            $net += (float) $item->line_total;
        }

        [$orderType, $orderValue] = $this->orders->normalizeDiscount($attributes['discount_type'] ?? null, $attributes['discount_value'] ?? null);
        $orderPercent = $net > 0 ? $this->pricing->lineDiscount($net, $orderType, $orderValue) / $net * 100 : 0;
        $worst = max($worst, round($orderPercent, 2));

        $hasDiscount = $priceLowered || $worst > 0;

        if ($hasDiscount && ! $actor->can('take-payment')) {
            throw new AuthorizationException('You cannot give discounts.');
        }

        if ($priceLowered && ! $actor->isAdmin() && ! $this->pos->allow_price_override) {
            throw new AuthorizationException('Price override is turned off in Settings. Ask a manager.');
        }

        if ($this->pricing->exceedsCashierLimit($actor, $worst)) {
            $this->orders->requirePin($actor, $pin, $priceLowered ? ApprovalAction::PriceOverride : ApprovalAction::Discount, null, $worst, [
                'scope' => 'cart',
            ]);
        }
    }
}
