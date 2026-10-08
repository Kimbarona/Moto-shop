<?php

namespace App\Reports\Returns;

use App\Enums\Disposition;
use App\Models\ReturnItem;
use App\Models\SalesReturn;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

class ReturnsReport extends Report
{
    public static function key(): string
    {
        return 'returns';
    }

    public static function title(): string
    {
        return 'Returns & Refunds';
    }

    public static function group(): string
    {
        return 'Returns';
    }

    public static function description(): string
    {
        return 'Returns by reason and by disposition, refunds paid out, and every return.';
    }

    protected function summary(Period $period): array
    {
        $returns = SalesReturn::query()->whereBetween('date', $period->range());

        return [
            'Returns' => [(clone $returns)->count(), 'int'],
            'Value returned' => [(float) (clone $returns)->sum('return_total'), 'money'],
            'Refunded (money out)' => [(float) (clone $returns)->sum('refund_amount'), 'money'],
        ];
    }

    protected function sections(Period $period): array
    {
        $returns = SalesReturn::query()
            ->with(['order.customer', 'refundMethod', 'processor', 'approver', 'exchangeOrder'])
            ->whereBetween('date', $period->range())
            ->orderBy('date')
            ->get();

        $byReason = $returns->groupBy(fn (SalesReturn $r) => $r->reason->getLabel())
            ->map(fn ($group, $reason) => [
                'reason' => $reason,
                'returns' => $group->count(),
                'value' => round((float) $group->sum('return_total'), 2),
            ])->values()->all();

        $lines = ReturnItem::query()
            ->with('orderItem')
            ->whereIn('return_id', $returns->pluck('id'))
            ->get();

        $byDisposition = $lines->groupBy(fn (ReturnItem $line) => $line->disposition?->getLabel() ?? 'Labor (refund only)')
            ->map(fn ($group, $label) => [
                'disposition' => $label,
                'qty' => round((float) $group->sum('qty'), 2),
                'value' => round((float) $group->sum('amount'), 2),
                'cost' => round((float) $group->sum(fn (ReturnItem $l) => (float) $l->qty * (float) $l->unit_cost), 2),
            ])->values()->all();

        $list = $returns->map(fn (SalesReturn $r) => [
            'date' => $r->date?->toDateTimeString(),
            'return_no' => $r->return_no,
            'slip' => $r->order?->slip_no,
            'customer' => $r->order?->customer?->name,
            'reason' => $r->reason->getLabel(),
            'resolution' => $r->isExchange()
                ? 'Exchange'.($r->exchangeOrder ? ' → '.$r->exchangeOrder->slip_no : ' (credit unused)')
                : 'Refund',
            'items' => $lines->where('return_id', $r->id)->map(fn (ReturnItem $l) => rtrim(rtrim(number_format((float) $l->qty, 2), '0'), '.').'× '.$l->orderItem?->description
                .($l->disposition === Disposition::Defective ? ' (defective)' : ''))->implode('; '),
            'value' => (float) $r->return_total,
            'refunded' => (float) $r->refund_amount,
            'method' => $r->refundMethod?->name,
            'by' => $r->processor?->name.($r->approver && $r->approver->isNot($r->processor) ? ' / '.$r->approver->name : ''),
        ])->all();

        return [
            new Section([
                Column::text('reason', 'Reason'),
                Column::int('returns', 'Returns')->total(),
                Column::money('value', 'Value')->total(),
            ], $byReason, 'By reason'),
            new Section([
                Column::text('disposition', 'Disposition'),
                Column::qty('qty', 'Qty')->total(),
                Column::money('value', 'Value')->total(),
                Column::money('cost', 'At cost')->total()->costOnly(),
            ], $byDisposition, 'By disposition'),
            new Section([
                Column::datetime('date', 'Date'),
                Column::text('return_no', 'Return'),
                Column::text('slip', 'Original slip'),
                Column::text('customer', 'Customer'),
                Column::text('reason', 'Reason'),
                Column::text('resolution', 'Resolution'),
                Column::text('items', 'Items'),
                Column::money('value', 'Value')->total(),
                Column::money('refunded', 'Money out')->total(),
                Column::text('method', 'Method'),
                Column::text('by', 'Processed / approved'),
            ], $list, 'Returns'),
        ];
    }
}
