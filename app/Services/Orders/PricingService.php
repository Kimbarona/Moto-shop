<?php

namespace App\Services\Orders;

use App\Enums\DiscountType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Settings\PosSettings;
use App\Settings\TaxSettings;
use Illuminate\Support\Collection;

/**
 * Line and order totals, VAT and discount allocation.
 *
 *  line gross      = qty × unit_price
 *  line discount   = peso amount, or % of line gross
 *  line_total      = gross − line discount
 *  order discount  = peso amount, or % of (Σ line_total)
 *  discount_share  = order discount spread over lines in proportion to line_total
 *  total           = Σ line_total − order discount (+ VAT when prices are VAT-exclusive)
 */
class PricingService
{
    public function __construct(
        private readonly TaxSettings $tax,
        private readonly PosSettings $pos,
    ) {}

    public function lineDiscount(float $gross, ?DiscountType $type, float $value): float
    {
        $discount = match ($type) {
            DiscountType::Percent => $gross * min(100, max(0, $value)) / 100,
            DiscountType::Amount => max(0, $value),
            default => 0,
        };

        return round(min($gross, $discount), 2);
    }

    /**
     * Recompute a line's discount and line_total (does not save).
     */
    public function applyLine(OrderItem $item): OrderItem
    {
        $gross = $item->gross();
        $discount = $this->lineDiscount($gross, $item->discount_type, (float) $item->discount_value);

        $item->discount = $discount;
        $item->line_total = round($gross - $discount, 2);

        return $item;
    }

    /**
     * Recompute every line, allocate the order discount, compute VAT and totals; saves.
     */
    public function recalculate(Order $order): Order
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $order->items()->get();

        foreach ($items as $item) {
            $this->applyLine($item);
        }

        $subtotal = round($items->sum(fn (OrderItem $item) => $item->gross()), 2);
        $lineDiscounts = round($items->sum(fn (OrderItem $item) => (float) $item->discount), 2);
        $afterLines = round($subtotal - $lineDiscounts, 2);
        $orderDiscount = $this->lineDiscount($afterLines, $order->discount_type, (float) $order->discount_value);

        $this->allocate($items, $orderDiscount);

        foreach ($items as $item) {
            if ($item->isDirty()) {
                $item->saveQuietly();
            }
        }

        $net = round($afterLines - $orderDiscount, 2);
        [$vatable, $vat, $total] = $this->vat($net);

        $order->forceFill([
            'subtotal' => $subtotal,
            'discount_total' => round($lineDiscounts + $orderDiscount, 2),
            'vatable' => $vatable,
            'vat_amount' => $vat,
            'total' => $total,
        ])->saveQuietly();

        return $order->setRelation('items', $items);
    }

    /**
     * Spread the order discount over lines by line_total; the last line takes the rounding.
     *
     * @param  Collection<int, OrderItem>  $items
     */
    public function allocate(Collection $items, float $orderDiscount): void
    {
        $base = $items->sum(fn (OrderItem $item) => (float) $item->line_total);
        $remaining = $orderDiscount;
        $eligible = $items->filter(fn (OrderItem $item) => (float) $item->line_total > 0)->values();

        foreach ($items as $item) {
            $item->discount_share = 0;
        }

        foreach ($eligible as $i => $item) {
            $share = $i === $eligible->count() - 1
                ? $remaining
                : round($orderDiscount * (float) $item->line_total / $base, 2);

            $share = min($share, (float) $item->line_total);
            $item->discount_share = $share;
            $remaining = round($remaining - $share, 2);
        }
    }

    /**
     * @return array{0: float, 1: float, 2: float} [vatable, vat, total]
     */
    public function vat(float $net): array
    {
        if (! $this->tax->vat_registered) {
            return [0.0, 0.0, $net];
        }

        $rate = $this->tax->vat_rate / 100;

        if ($this->tax->prices_vat_inclusive) {
            $vatable = round($net / (1 + $rate), 2);

            return [$vatable, round($net - $vatable, 2), $net];
        }

        $vat = round($net * $rate, 2);

        return [$net, $vat, round($net + $vat, 2)];
    }

    /**
     * Effective reduction of a line versus its original (SRP / labor fee) value, in %.
     * Package lines are measured against the package's own pre-approved discount.
     */
    public function lineReductionPercent(OrderItem $item): float
    {
        $original = (float) $item->original_price * (float) $item->qty;

        if ($original <= 0) {
            return 0.0;
        }

        $this->applyLine($item);

        return round(max(0, ($original - (float) $item->line_total) / $original * 100), 2);
    }

    /**
     * The order discount as a % of the amount it applies to.
     */
    public function orderDiscountPercent(Order $order, ?DiscountType $type, float $value): float
    {
        $base = round($order->items()->get()->sum(fn (OrderItem $item) => (float) $this->applyLine($item)->line_total), 2);

        if ($base <= 0) {
            return 0.0;
        }

        return round($this->lineDiscount($base, $type, $value) / $base * 100, 2);
    }

    public function exceedsCashierLimit(User $actor, float $percent): bool
    {
        return ! $actor->isAdmin() && $percent > (float) $this->pos->cashier_max_discount_percent + 0.0001;
    }
}
