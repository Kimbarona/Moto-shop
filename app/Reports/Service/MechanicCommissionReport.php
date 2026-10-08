<?php

namespace App\Reports\Service;

use App\Models\OrderItem;
use App\Models\ReturnItem;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

/**
 * Commission earned when labor is paid, less commission reversed by refunds — each in
 * the period it happened, so a return later on reduces that later period's payout.
 */
class MechanicCommissionReport extends Report
{
    use MechanicReport;

    public static function key(): string
    {
        return 'mechanic-commission';
    }

    public static function title(): string
    {
        return 'Mechanic Commission';
    }

    public static function group(): string
    {
        return 'Service';
    }

    public static function description(): string
    {
        return 'Commission per mechanic for the period, with each labor line and reversal.';
    }

    protected function sections(Period $period): array
    {
        $summary = array_values(array_map(fn (array $m) => [
            'mechanic' => $m['mechanic'],
            'jobs' => $m['jobs'],
            'commission' => $m['commission'],
            'reversed' => $m['reversed'] > 0 ? -$m['reversed'] : 0.0,
            'payable' => round($m['commission'] - $m['reversed'], 2),
        ], $this->mechanicFigures($period)));

        $earned = OrderItem::query()
            ->with(['order', 'mechanic'])
            ->where('line_type', 'labor')
            ->whereNotNull('mechanic_id')
            ->whereHas('order', fn ($q) => $q->paid()->whereBetween('paid_at', $period->range()))
            ->get()
            ->map(fn (OrderItem $item) => [
                'date' => $item->order->paid_at?->toDateTimeString(),
                'mechanic' => $item->mechanic?->name,
                'document' => $item->order->slip_no,
                'description' => $item->description,
                'labor' => $item->paidAmount($item->order->paidRatio()),
                'commission' => (float) $item->commission_amount,
            ]);

        $reversed = ReturnItem::query()
            ->with(['salesReturn', 'orderItem.mechanic'])
            ->where('commission_reversed', '>', 0)
            ->whereHas('salesReturn', fn ($q) => $q->whereBetween('date', $period->range()))
            ->get()
            ->map(fn (ReturnItem $line) => [
                'date' => $line->salesReturn->date?->toDateTimeString(),
                'mechanic' => $line->orderItem?->mechanic?->name,
                'document' => $line->salesReturn->return_no,
                'description' => 'Refund: '.$line->orderItem?->description,
                'labor' => -(float) $line->amount,
                'commission' => -(float) $line->commission_reversed,
            ]);

        $lines = $earned->concat($reversed)->sortBy([['mechanic', 'asc'], ['date', 'asc']])->values()->all();

        return [
            new Section([
                Column::text('mechanic', 'Mechanic'),
                Column::int('jobs', 'Jobs')->total(),
                Column::money('commission', 'Earned')->total(),
                Column::money('reversed', 'Reversed')->total(),
                Column::money('payable', 'Payable')->total(),
            ], $summary),
            new Section([
                Column::datetime('date', 'Date'),
                Column::text('mechanic', 'Mechanic'),
                Column::text('document', 'Slip / return'),
                Column::text('description', 'Labor'),
                Column::money('labor', 'Amount')->total(),
                Column::money('commission', 'Commission')->total(),
            ], $lines, 'Lines'),
        ];
    }
}
