<?php

namespace App\Reports\Inventory;

use App\Models\Product;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use Illuminate\Support\Facades\DB;

/**
 * Parts at or below their reorder level, with the supplier they last came from.
 */
class LowStockReport extends Report
{
    public static function key(): string
    {
        return 'low-stock';
    }

    public static function title(): string
    {
        return 'Low Stock / Reorder List';
    }

    public static function group(): string
    {
        return 'Inventory';
    }

    public static function description(): string
    {
        return 'Parts at or below reorder level, with the last supplier and cost.';
    }

    public static function ability(): string
    {
        return 'view-inventory-reports';
    }

    public static function usesDates(): bool
    {
        return false;
    }

    protected function sections(Period $period): array
    {
        $lastReceipt = DB::table('stock_receiving_items')
            ->join('stock_receivings', 'stock_receivings.id', '=', 'stock_receiving_items.stock_receiving_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'stock_receivings.supplier_id')
            ->where('stock_receivings.status', 'posted')
            ->orderByDesc('stock_receivings.posted_at')
            ->orderByDesc('stock_receiving_items.id')
            ->get(['stock_receiving_items.product_id', 'suppliers.name as supplier', 'stock_receivings.posted_at', 'stock_receiving_items.unit_cost'])
            ->unique('product_id')
            ->keyBy('product_id');

        $rows = Product::query()
            ->active()
            ->withStockLevels()
            ->with(['unit', 'category'])
            ->lowStock()
            ->orderBy('name')
            ->get()
            ->map(function (Product $p) use ($lastReceipt) {
                $receipt = $lastReceipt[$p->id] ?? null;
                $suggested = max(0, round((float) $p->reorder_level * 2 - (float) $p->qty_on_hand, 2));

                return [
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category' => $p->category?->name,
                    'on_hand' => (float) $p->qty_on_hand,
                    'available' => round((float) $p->available_qty, 2),
                    'reorder_level' => (float) $p->reorder_level,
                    'suggested' => $suggested,
                    'unit' => $p->unit?->abbreviation,
                    'supplier' => $receipt?->supplier ?? '—',
                    'last_received' => $receipt?->posted_at,
                    'last_cost' => $receipt ? round((float) $receipt->unit_cost, 2) : null,
                    'est_cost' => $receipt ? round($suggested * (float) $receipt->unit_cost, 2) : null,
                ];
            })
            ->values()
            ->all();

        return [new Section([
            Column::text('sku', 'SKU'),
            Column::text('name', 'Part'),
            Column::text('category', 'Category'),
            Column::qty('on_hand', 'On hand'),
            Column::qty('available', 'Available'),
            Column::qty('reorder_level', 'Reorder at'),
            Column::qty('suggested', 'Suggested order'),
            Column::text('unit', 'Unit'),
            Column::text('supplier', 'Last supplier'),
            Column::date('last_received', 'Last received'),
            Column::money('last_cost', 'Last cost')->costOnly(),
            Column::money('est_cost', 'Est. order cost')->total()->costOnly(),
        ], $rows)];
    }
}
