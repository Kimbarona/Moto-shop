<?php

namespace App\Reports\Service;

use App\Reports\Period;
use App\Services\Reports\SalesLedger;
use Illuminate\Support\Facades\DB;

/**
 * Shared figures for the mechanic reports: labor paid in the period, less labor refunded
 * in the period; commission earned at payment, less commission reversed by returns.
 */
trait MechanicReport
{
    /**
     * @return array<int, array{mechanic: string, jobs: int, lines: int, labor: float, labor_refunded: float, commission: float, reversed: float}>
     */
    protected function mechanicFigures(Period $period): array
    {
        $labor = app(SalesLedger::class)->saleLines($period)
            ->where('order_items.line_type', 'labor')
            ->whereNotNull('order_items.mechanic_id')
            ->groupBy('order_items.mechanic_id')
            ->selectRaw('order_items.mechanic_id, COUNT(DISTINCT orders.id) as jobs, COUNT(*) as line_count, SUM('.SalesLedger::PAID.') as labor, SUM(order_items.commission_amount) as commission')
            ->get()->keyBy('mechanic_id');

        $refunds = app(SalesLedger::class)->refundLines($period)
            ->where('order_items.line_type', 'labor')
            ->whereNotNull('order_items.mechanic_id')
            ->groupBy('order_items.mechanic_id')
            ->selectRaw('order_items.mechanic_id, SUM(return_items.amount) as refunded, SUM(return_items.commission_reversed) as reversed')
            ->get()->keyBy('mechanic_id');

        $ids = $labor->keys()->merge($refunds->keys())->unique();
        $names = DB::table('mechanics')->whereIn('id', $ids)->pluck('name', 'id');

        return $ids->mapWithKeys(fn ($id) => [$id => [
            'mechanic' => $names[$id] ?? 'Unknown',
            'jobs' => (int) ($labor[$id]->jobs ?? 0),
            'lines' => (int) ($labor[$id]->line_count ?? 0),
            'labor' => round((float) ($labor[$id]->labor ?? 0), 2),
            'labor_refunded' => round((float) ($refunds[$id]->refunded ?? 0), 2),
            'commission' => round((float) ($labor[$id]->commission ?? 0), 2),
            'reversed' => round((float) ($refunds[$id]->reversed ?? 0), 2),
        ]])->sortBy('mechanic')->all();
    }
}
