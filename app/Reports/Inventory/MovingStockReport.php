<?php

namespace App\Reports\Inventory;

use App\Models\Product;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use App\Services\Reports\SalesLedger;
use App\Settings\InventorySettings;
use Illuminate\Support\Facades\DB;

/**
 * Fast movers in the period, and dead stock: parts with stock but no sale in X days.
 */
class MovingStockReport extends Report
{
    public static function key(): string
    {
        return 'moving-stock';
    }

    public static function title(): string
    {
        return 'Fast-moving & Dead Stock';
    }

    public static function group(): string
    {
        return 'Inventory';
    }

    public static function description(): string
    {
        return 'Best sellers in the period; parts with no sale in X days (Settings → Inventory).';
    }

    public static function ability(): string
    {
        return 'view-inventory-reports';
    }

    public static function optionFields(): array
    {
        return [
            'days' => [
                'label' => 'No sale in (days)',
                'type' => 'number',
                'default' => rescue(fn () => app(InventorySettings::class)->dead_stock_days, 90, report: false),
            ],
        ];
    }

    protected function sections(Period $period): array
    {
        $fast = app(SalesLedger::class)->saleLines($period)
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('order_items.line_type', 'part')
            ->groupBy('products.id', 'products.sku', 'products.name', 'products.qty_on_hand')
            ->selectRaw('products.sku, products.name, products.qty_on_hand as on_hand, SUM(order_items.qty) as qty, COUNT(DISTINCT orders.id) as orders, SUM('.SalesLedger::PAID.') as sales')
            ->orderByDesc('qty')
            ->limit(30)
            ->get()
            ->map(fn ($row) => [
                'sku' => $row->sku,
                'name' => $row->name,
                'qty' => round((float) $row->qty, 2),
                'orders' => (int) $row->orders,
                'sales' => round((float) $row->sales, 2),
                'on_hand' => (float) $row->on_hand,
            ])->all();

        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $lastSale = DB::table('stock_movements')
            ->where('type', 'sale')
            ->groupBy('product_id')
            ->selectRaw('product_id, MAX(created_at) as last_sale')
            ->pluck('last_sale', 'product_id');

        $dead = Product::query()
            ->active()
            ->where('qty_on_hand', '>', 0)
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $p) => ! isset($lastSale[$p->id]) || $lastSale[$p->id] < $cutoff)
            ->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'on_hand' => (float) $p->qty_on_hand,
                'last_sale' => $lastSale[$p->id] ?? null,
                'days' => isset($lastSale[$p->id]) ? (int) now()->diffInDays($lastSale[$p->id], true) : null,
                'value' => round((float) $p->qty_on_hand * (float) $p->avg_cost, 2),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            new Section([
                Column::text('sku', 'SKU'),
                Column::text('name', 'Part'),
                Column::qty('qty', 'Qty sold')->total(),
                Column::int('orders', 'Orders'),
                Column::money('sales', 'Sales')->total(),
                Column::qty('on_hand', 'On hand'),
            ], $fast, 'Fast-moving (top 30 in the period)'),
            new Section([
                Column::text('sku', 'SKU'),
                Column::text('name', 'Part'),
                Column::qty('on_hand', 'On hand'),
                Column::datetime('last_sale', 'Last sold'),
                Column::int('days', 'Days since'),
                Column::money('value', 'Value at cost')->total()->costOnly(),
            ], $dead, "Slow / dead stock (no sale in {$days} days)"),
        ];
    }
}
