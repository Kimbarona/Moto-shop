<?php

namespace App\Reports\Inventory;

use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use Illuminate\Support\Facades\DB;

/**
 * Posted stock adjustments and physical count variances in the period.
 */
class AdjustmentVarianceReport extends Report
{
    public static function key(): string
    {
        return 'adjustments-variances';
    }

    public static function title(): string
    {
        return 'Adjustments & Count Variances';
    }

    public static function group(): string
    {
        return 'Inventory';
    }

    public static function description(): string
    {
        return 'Every posted adjustment line and physical-count difference, with value.';
    }

    public static function ability(): string
    {
        return 'view-inventory-reports';
    }

    protected function sections(Period $period): array
    {
        $adjustments = DB::table('stock_adjustment_items')
            ->join('stock_adjustments', 'stock_adjustments.id', '=', 'stock_adjustment_items.stock_adjustment_id')
            ->join('products', 'products.id', '=', 'stock_adjustment_items.product_id')
            ->leftJoin('users', 'users.id', '=', 'stock_adjustments.posted_by')
            ->where('stock_adjustments.status', 'posted')
            ->whereBetween('stock_adjustments.posted_at', $period->range())
            ->orderBy('stock_adjustments.posted_at')
            ->get([
                'stock_adjustments.posted_at', 'stock_adjustments.adjustment_no', 'stock_adjustments.reason', 'stock_adjustments.remarks',
                'products.sku', 'products.name', 'stock_adjustment_items.qty', 'stock_adjustment_items.unit_cost', 'users.name as user',
            ])
            ->map(fn ($row) => [
                'date' => $row->posted_at,
                'document' => $row->adjustment_no,
                'reason' => ucwords(str_replace('_', ' ', (string) $row->reason)),
                'sku' => $row->sku,
                'name' => $row->name,
                'qty' => round((float) $row->qty, 2),
                'value' => round((float) $row->qty * (float) $row->unit_cost, 2),
                'remarks' => $row->remarks,
                'user' => $row->user,
            ])->all();

        $variances = DB::table('stock_count_items')
            ->join('stock_counts', 'stock_counts.id', '=', 'stock_count_items.stock_count_id')
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->leftJoin('users', 'users.id', '=', 'stock_counts.posted_by')
            ->where('stock_counts.status', 'posted')
            ->whereNotNull('stock_count_items.counted_qty')
            ->whereColumn('stock_count_items.counted_qty', '!=', 'stock_count_items.system_qty')
            ->whereBetween('stock_counts.posted_at', $period->range())
            ->orderBy('stock_counts.posted_at')
            ->get([
                'stock_counts.posted_at', 'stock_counts.count_no', 'products.sku', 'products.name',
                'stock_count_items.system_qty', 'stock_count_items.counted_qty', 'stock_count_items.unit_cost', 'users.name as user',
            ])
            ->map(function ($row) {
                $variance = round((float) $row->counted_qty - (float) $row->system_qty, 2);

                return [
                    'date' => $row->posted_at,
                    'document' => $row->count_no,
                    'sku' => $row->sku,
                    'name' => $row->name,
                    'system' => (float) $row->system_qty,
                    'counted' => (float) $row->counted_qty,
                    'variance' => $variance,
                    'value' => round($variance * (float) $row->unit_cost, 2),
                    'user' => $row->user,
                ];
            })->all();

        return [
            new Section([
                Column::datetime('date', 'Posted'),
                Column::text('document', 'Adjustment'),
                Column::text('reason', 'Reason'),
                Column::text('sku', 'SKU'),
                Column::text('name', 'Part'),
                Column::qty('qty', 'Qty')->total(),
                Column::money('value', 'Value')->total()->costOnly(),
                Column::text('remarks', 'Remarks'),
                Column::text('user', 'By'),
            ], $adjustments, 'Stock adjustments'),
            new Section([
                Column::datetime('date', 'Posted'),
                Column::text('document', 'Count'),
                Column::text('sku', 'SKU'),
                Column::text('name', 'Part'),
                Column::qty('system', 'System'),
                Column::qty('counted', 'Counted'),
                Column::qty('variance', 'Variance')->total(),
                Column::money('value', 'Value')->total()->costOnly(),
                Column::text('user', 'By'),
            ], $variances, 'Physical count variances'),
        ];
    }
}
