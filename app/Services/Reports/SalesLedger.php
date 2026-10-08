<?php

namespace App\Services\Reports;

use App\Reports\Period;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one place sales figures are defined, used by the dashboard and every report:
 *
 *  - Sales are PAID orders by payment date (voided orders are out; a same-day void
 *    takes the sale back out completely).
 *  - Refunds are returns by return date, shown as negative lines.
 *  - Net sales = sales − refunds (VAT included, i.e. what customers paid).
 *  - VAT = VAT on sales − VAT inside refunds.
 *  - COGS = unit_cost snapshots of parts sold − parts returned back to stock (a defective
 *    return never comes back to the shelf, so its cost stays an expense).
 *  - Gross profit = net sales without VAT − COGS.
 */
class SalesLedger
{
    /** What the customer paid for a line, VAT included (see Order::paidRatio()). */
    public const PAID = '(order_items.line_total - order_items.discount_share) * COALESCE(orders.total / NULLIF(orders.subtotal - orders.discount_total, 0), 1)';

    /** A line's sales without VAT (see Order::exVatRatio()). */
    public const EX_VAT = '(order_items.line_total - order_items.discount_share) * COALESCE((orders.total - orders.vat_amount) / NULLIF(orders.subtotal - orders.discount_total, 0), 1)';

    /** VAT inside a refunded amount (same share as on the original order). */
    public const REFUND_VAT = 'return_items.amount * COALESCE(orders.vat_amount / NULLIF(orders.total, 0), 0)';

    /** Cost coming back to the shelf with a return. */
    public const RETURNED_COST = "CASE WHEN return_items.disposition = 'back_to_stock' THEN return_items.qty * return_items.unit_cost ELSE 0 END";

    public function paidOrders(Period $period): Builder
    {
        return DB::table('orders')
            ->where('orders.status', 'paid')
            ->whereBetween('orders.paid_at', $period->range());
    }

    /**
     * Order lines of paid orders in the period (joined to orders).
     */
    public function saleLines(Period $period): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'paid')
            ->whereBetween('orders.paid_at', $period->range());
    }

    /**
     * Returned lines in the period (joined to returns, the original line and its order).
     */
    public function refundLines(Period $period): Builder
    {
        return DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('returns.date', $period->range());
    }

    /**
     * @return array{orders: int, gross: float, discounts: float, sales: float, refunds: float, net: float, vat: float, net_ex_vat: float, cogs: float, gross_profit: float, margin: float|null, parts: float, labor: float, parts_refunds: float, labor_refunds: float}
     */
    public function summary(Period $period): array
    {
        return $this->compute($period, null)['all'] ?? $this->blank();
    }

    /**
     * The same figures per day (every day in the period, zeros included).
     *
     * @return array<string, array<string, mixed>>
     */
    public function daily(Period $period): array
    {
        $computed = $this->compute($period, 'day');

        return collect($period->days())
            ->mapWithKeys(fn (string $day) => [$day => $computed[$day] ?? $this->blank()])
            ->all();
    }

    /**
     * Money received by payment method, less refunds paid out by that method.
     *
     * @return list<array{method: string, type: string, payments: int, received: float, refunded: float, net: float}>
     */
    public function byPaymentMethod(Period $period): array
    {
        $received = DB::table('payments')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->whereNull('payments.voided_at')
            ->whereBetween('payments.paid_at', $period->range())
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.type', 'payment_methods.sort_order')
            ->selectRaw('payment_methods.id, payment_methods.name, payment_methods.type, payment_methods.sort_order, COUNT(*) as payments, SUM(payments.amount) as amount')
            ->get()->keyBy('id');

        $refunded = DB::table('returns')
            ->join('payment_methods', 'payment_methods.id', '=', 'returns.refund_method_id')
            ->where('returns.refund_amount', '>', 0)
            ->whereBetween('returns.date', $period->range())
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.type', 'payment_methods.sort_order')
            ->selectRaw('payment_methods.id, payment_methods.name, payment_methods.type, payment_methods.sort_order, SUM(returns.refund_amount) as amount')
            ->get()->keyBy('id');

        return $received->keys()->merge($refunded->keys())->unique()
            ->map(function ($id) use ($received, $refunded) {
                $method = $received[$id] ?? $refunded[$id];
                $in = round((float) ($received[$id]->amount ?? 0), 2);
                $out = round((float) ($refunded[$id]->amount ?? 0), 2);

                return [
                    'method' => $method->name,
                    'type' => $method->type,
                    'sort' => (int) $method->sort_order,
                    'payments' => (int) ($received[$id]->payments ?? 0),
                    'received' => $in,
                    'refunded' => $out,
                    'net' => round($in - $out, 2),
                ];
            })
            ->sortBy('sort')
            ->map(fn (array $row) => collect($row)->except('sort')->all())
            ->values()
            ->all();
    }

    /**
     * Net sales by category (labor is its own "Labor" category).
     *
     * @return list<array{category: string, qty: float, sales: float, refunds: float, net: float, net_ex_vat: float, cogs: float, gross_profit: float}>
     */
    public function byCategory(Period $period): array
    {
        $label = "CASE WHEN order_items.line_type = 'labor' THEN 'Labor' ELSE COALESCE(categories.name, 'Uncategorized') END";

        $sales = $this->saleLines($period)
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupByRaw($label)
            ->selectRaw("{$label} as label, SUM(order_items.qty) as qty, SUM(".self::PAID.') as sales, SUM('.self::EX_VAT.') as ex_vat, SUM(CASE WHEN order_items.line_type = \'part\' THEN order_items.qty * order_items.unit_cost ELSE 0 END) as cogs')
            ->get()->keyBy('label');

        $refunds = $this->refundLines($period)
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupByRaw($label)
            ->selectRaw("{$label} as label, SUM(return_items.amount) as refunds, SUM(return_items.amount - ".self::REFUND_VAT.') as refunds_ex_vat, SUM('.self::RETURNED_COST.') as cost_back')
            ->get()->keyBy('label');

        return $sales->keys()->merge($refunds->keys())->unique()
            ->map(function ($label) use ($sales, $refunds) {
                $s = $sales[$label] ?? null;
                $r = $refunds[$label] ?? null;
                $exVat = round((float) ($s->ex_vat ?? 0) - (float) ($r->refunds_ex_vat ?? 0), 2);
                $cogs = round((float) ($s->cogs ?? 0) - (float) ($r->cost_back ?? 0), 2);

                return [
                    'category' => (string) $label,
                    'qty' => round((float) ($s->qty ?? 0), 2),
                    'sales' => round((float) ($s->sales ?? 0), 2),
                    'refunds' => round((float) ($r->refunds ?? 0), 2),
                    'net' => round((float) ($s->sales ?? 0) - (float) ($r->refunds ?? 0), 2),
                    'net_ex_vat' => $exVat,
                    'cogs' => $cogs,
                    'gross_profit' => round($exVat - $cogs, 2),
                ];
            })
            ->sortByDesc('net')
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function compute(Period $period, ?string $groupBy): array
    {
        $orderKey = $groupBy === 'day' ? 'DATE(orders.paid_at)' : "'all'";
        $returnKey = $groupBy === 'day' ? 'DATE(returns.date)' : "'all'";

        $orders = $this->paidOrders($period)
            ->groupByRaw($orderKey)
            ->selectRaw("{$orderKey} as k, COUNT(*) as orders, SUM(subtotal) as gross, SUM(discount_total) as discounts, SUM(total) as sales, SUM(vat_amount) as vat")
            ->get()->keyBy('k');

        $lines = $this->saleLines($period)
            ->groupByRaw($orderKey)
            ->selectRaw("{$orderKey} as k,
                SUM(CASE WHEN order_items.line_type = 'part' THEN order_items.qty * order_items.unit_cost ELSE 0 END) as cogs,
                SUM(CASE WHEN order_items.line_type = 'part' THEN ".self::PAID." ELSE 0 END) as parts,
                SUM(CASE WHEN order_items.line_type = 'labor' THEN ".self::PAID.' ELSE 0 END) as labor')
            ->get()->keyBy('k');

        $refunds = $this->refundLines($period)
            ->groupByRaw($returnKey)
            ->selectRaw("{$returnKey} as k,
                SUM(return_items.amount) as refunds,
                SUM(".self::REFUND_VAT.') as refund_vat,
                SUM('.self::RETURNED_COST.") as cost_back,
                SUM(CASE WHEN order_items.line_type = 'part' THEN return_items.amount ELSE 0 END) as parts_refunds,
                SUM(CASE WHEN order_items.line_type = 'labor' THEN return_items.amount ELSE 0 END) as labor_refunds")
            ->get()->keyBy('k');

        $result = [];

        foreach ($orders->keys()->merge($refunds->keys())->unique() as $key) {
            $o = $orders[$key] ?? null;
            $l = $lines[$key] ?? null;
            $r = $refunds[$key] ?? null;

            $sales = round((float) ($o->sales ?? 0), 2);
            $refundTotal = round((float) ($r->refunds ?? 0), 2);
            $net = round($sales - $refundTotal, 2);
            $vat = round((float) ($o->vat ?? 0) - (float) ($r->refund_vat ?? 0), 2);
            $netExVat = round($net - $vat, 2);
            $cogs = round((float) ($l->cogs ?? 0) - (float) ($r->cost_back ?? 0), 2);
            $profit = round($netExVat - $cogs, 2);

            $result[(string) $key] = [
                'orders' => (int) ($o->orders ?? 0),
                'gross' => round((float) ($o->gross ?? 0), 2),
                'discounts' => round((float) ($o->discounts ?? 0), 2),
                'sales' => $sales,
                'refunds' => $refundTotal,
                'net' => $net,
                'vat' => $vat,
                'net_ex_vat' => $netExVat,
                'cogs' => $cogs,
                'gross_profit' => $profit,
                'margin' => $netExVat != 0.0 ? round($profit / $netExVat * 100, 1) : null,
                'parts' => round((float) ($l->parts ?? 0), 2),
                'labor' => round((float) ($l->labor ?? 0), 2),
                'parts_refunds' => round((float) ($r->parts_refunds ?? 0), 2),
                'labor_refunds' => round((float) ($r->labor_refunds ?? 0), 2),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(): array
    {
        return [
            'orders' => 0, 'gross' => 0.0, 'discounts' => 0.0, 'sales' => 0.0, 'refunds' => 0.0, 'net' => 0.0,
            'vat' => 0.0, 'net_ex_vat' => 0.0, 'cogs' => 0.0, 'gross_profit' => 0.0, 'margin' => null,
            'parts' => 0.0, 'labor' => 0.0, 'parts_refunds' => 0.0, 'labor_refunds' => 0.0,
        ];
    }
}
