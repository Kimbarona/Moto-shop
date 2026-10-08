<?php

namespace App\Reports\Sales;

use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use App\Services\Reports\SalesLedger;
use Illuminate\Support\Facades\DB;

class SalesByCashierReport extends Report
{
    public static function key(): string
    {
        return 'sales-by-cashier';
    }

    public static function title(): string
    {
        return 'Sales by Cashier';
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return 'Orders, sales and discounts per cashier; refunds and voids they processed.';
    }

    protected function sections(Period $period): array
    {
        $sales = app(SalesLedger::class)->paidOrders($period)
            ->groupBy('cashier_id')
            ->selectRaw('cashier_id as user_id, COUNT(*) as orders, SUM(discount_total) as discounts, SUM(total) as sales')
            ->get()->keyBy('user_id');

        $refunds = DB::table('returns')
            ->whereBetween('date', $period->range())
            ->groupBy('processed_by')
            ->selectRaw('processed_by as user_id, COUNT(*) as returns, SUM(return_total) as refunds')
            ->get()->keyBy('user_id');

        $voids = DB::table('orders')
            ->whereNotNull('voided_at')
            ->whereBetween('voided_at', $period->range())
            ->groupBy('voided_by')
            ->selectRaw('voided_by as user_id, COUNT(*) as voids')
            ->get()->keyBy('user_id');

        $ids = $sales->keys()->merge($refunds->keys())->merge($voids->keys())->unique()->filter();
        $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');

        $rows = $ids->map(fn ($id) => [
            'cashier' => $names[$id] ?? 'Unknown',
            'orders' => (int) ($sales[$id]->orders ?? 0),
            'sales' => round((float) ($sales[$id]->sales ?? 0), 2),
            'discounts' => round((float) ($sales[$id]->discounts ?? 0), 2),
            'returns' => (int) ($refunds[$id]->returns ?? 0),
            'refunds' => isset($refunds[$id]) ? -round((float) $refunds[$id]->refunds, 2) : 0.0,
            'net' => round((float) ($sales[$id]->sales ?? 0) - (float) ($refunds[$id]->refunds ?? 0), 2),
            'voids' => (int) ($voids[$id]->voids ?? 0),
        ])->sortByDesc('sales')->values()->all();

        return [new Section([
            Column::text('cashier', 'Cashier'),
            Column::int('orders', 'Orders')->total(),
            Column::money('sales', 'Sales')->total(),
            Column::money('discounts', 'Discounts given')->total(),
            Column::int('returns', 'Returns')->total(),
            Column::money('refunds', 'Refunds')->total(),
            Column::money('net', 'Net')->total(),
            Column::int('voids', 'Voids')->total(),
        ], $rows)];
    }
}
