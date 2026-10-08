<?php

namespace App\Reports\Inventory;

use App\Enums\MovementType;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use Illuminate\Support\Facades\DB;

/**
 * Opening balance, movements by type, closing balance per part. Every qty change is a
 * stock movement (StockService is the only writer), so opening = sum of movements before.
 */
class StockMovementSummaryReport extends Report
{
    public static function key(): string
    {
        return 'stock-movement-summary';
    }

    public static function title(): string
    {
        return 'Stock Movement Summary';
    }

    public static function group(): string
    {
        return 'Inventory';
    }

    public static function description(): string
    {
        return 'Opening, in, out, adjustments and closing qty per part.';
    }

    public static function ability(): string
    {
        return 'view-inventory-reports';
    }

    protected function sections(Period $period): array
    {
        $opening = DB::table('stock_movements')
            ->where('created_at', '<', $period->from)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty) as qty')
            ->pluck('qty', 'product_id');

        $moves = DB::table('stock_movements')
            ->whereBetween('created_at', $period->range())
            ->groupBy('product_id', 'type')
            ->selectRaw('product_id, type, SUM(qty) as qty')
            ->get()
            ->groupBy('product_id');

        $products = DB::table('products')
            ->whereIn('id', $moves->keys())
            ->orderBy('name')
            ->get(['id', 'sku', 'name']);

        $sum = fn ($group, array $types) => round((float) $group->whereIn('type', array_map(fn ($t) => $t->value, $types))->sum('qty'), 2);

        $rows = $products->map(function ($product) use ($moves, $opening, $sum) {
            $group = $moves[$product->id];
            $open = round((float) ($opening[$product->id] ?? 0), 2);
            $net = round((float) $group->sum('qty'), 2);

            return [
                'sku' => $product->sku,
                'name' => $product->name,
                'opening' => $open,
                'stock_in' => $sum($group, [MovementType::StockIn]),
                'sold' => $sum($group, [MovementType::Sale]),
                'returned' => $sum($group, [MovementType::ReturnToStock, MovementType::VoidReversal]),
                'adjusted' => $sum($group, [MovementType::AdjustmentIn, MovementType::AdjustmentOut, MovementType::CountVariance]),
                'defective' => $sum($group, [MovementType::DefectiveOut, MovementType::SupplierReturn]),
                'closing' => round($open + $net, 2),
            ];
        })->values()->all();

        return [new Section([
            Column::text('sku', 'SKU'),
            Column::text('name', 'Part'),
            Column::qty('opening', 'Opening'),
            Column::qty('stock_in', 'Stock in')->total(),
            Column::qty('sold', 'Sold')->total(),
            Column::qty('returned', 'Returned / voided')->total(),
            Column::qty('adjusted', 'Adjustments & counts')->total(),
            Column::qty('defective', 'Defective out')->total(),
            Column::qty('closing', 'Closing'),
        ], $rows)];
    }
}
