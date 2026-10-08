<?php

namespace App\Reports\Sales;

use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use App\Services\Reports\SalesLedger;

/**
 * Net sales per item, category or brand; refunds come off as negative amounts.
 */
class SalesByItemReport extends Report
{
    public static function key(): string
    {
        return 'sales-by-item';
    }

    public static function title(): string
    {
        return 'Sales by Item / Category / Brand';
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return 'Qty sold, sales, refunds and net, grouped the way you choose.';
    }

    public static function optionFields(): array
    {
        return [
            'group_by' => [
                'label' => 'Group by',
                'type' => 'select',
                'options' => ['item' => 'Item', 'category' => 'Category', 'brand' => 'Brand'],
                'default' => 'item',
            ],
        ];
    }

    protected function sections(Period $period): array
    {
        $ledger = app(SalesLedger::class);

        [$key, $label] = match ($this->option('group_by')) {
            'category' => [
                "CASE WHEN order_items.line_type = 'labor' THEN 'Labor' ELSE COALESCE(categories.name, 'Uncategorized') END",
                'Category',
            ],
            'brand' => [
                "CASE WHEN order_items.line_type = 'labor' THEN 'Labor' ELSE COALESCE(brands.name, 'No brand') END",
                'Brand',
            ],
            default => [
                "CONCAT(order_items.line_type, ':', COALESCE(order_items.product_id, order_items.service_id, 0))",
                'Item',
            ],
        };

        $join = fn ($query) => $query
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->groupByRaw($key);

        $sales = $join($ledger->saleLines($period))
            ->selectRaw("{$key} as k, MAX(order_items.description) as name, MAX(products.sku) as sku, MAX(order_items.line_type) as line_type,
                SUM(order_items.qty) as qty, SUM(".SalesLedger::PAID.') as sales, SUM('.SalesLedger::EX_VAT.") as ex_vat,
                SUM(CASE WHEN order_items.line_type = 'part' THEN order_items.qty * order_items.unit_cost ELSE 0 END) as cogs")
            ->get()->keyBy('k');

        $refunds = $join($ledger->refundLines($period))
            ->selectRaw("{$key} as k, MAX(order_items.description) as name, MAX(products.sku) as sku, MAX(order_items.line_type) as line_type,
                SUM(return_items.qty) as qty, SUM(return_items.amount) as refunds,
                SUM(return_items.amount - ".SalesLedger::REFUND_VAT.') as refunds_ex_vat,
                SUM('.SalesLedger::RETURNED_COST.') as cost_back')
            ->get()->keyBy('k');

        $rows = $sales->keys()->merge($refunds->keys())->unique()->map(function ($k) use ($sales, $refunds) {
            $s = $sales[$k] ?? null;
            $r = $refunds[$k] ?? null;
            $net = round((float) ($s->sales ?? 0) - (float) ($r->refunds ?? 0), 2);
            $exVat = (float) ($s->ex_vat ?? 0) - (float) ($r->refunds_ex_vat ?? 0);
            $profit = round($exVat - ((float) ($s->cogs ?? 0) - (float) ($r->cost_back ?? 0)), 2);

            return [
                'name' => $this->option('group_by') === 'item' ? ($s->name ?? $r->name) : (string) $k,
                'sku' => $this->option('group_by') === 'item' ? (($s->line_type ?? $r->line_type) === 'labor' ? 'Labor' : ($s->sku ?? $r->sku)) : null,
                'qty' => round((float) ($s->qty ?? 0), 2),
                'returned' => round((float) ($r->qty ?? 0), 2),
                'sales' => round((float) ($s->sales ?? 0), 2),
                'refunds' => $r ? -round((float) $r->refunds, 2) : 0.0,
                'net' => $net,
                'gross_profit' => $profit,
                'margin' => static::pct($profit, $exVat),
            ];
        })->sortByDesc('net')->values()->all();

        $columns = [
            Column::text('name', $label),
            ...($this->option('group_by') === 'item' ? [Column::text('sku', 'SKU')] : []),
            Column::qty('qty', 'Qty sold')->total(),
            Column::qty('returned', 'Qty returned')->total(),
            Column::money('sales', 'Sales')->total(),
            Column::money('refunds', 'Refunds')->total(),
            Column::money('net', 'Net sales')->total(),
            Column::money('gross_profit', 'Gross profit')->total()->costOnly(),
            Column::percent('margin', 'Margin')->costOnly(),
        ];

        return [new Section($columns, $rows)];
    }
}
