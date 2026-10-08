<?php

namespace App\Reports\Audit;

use App\Enums\ApprovalAction;
use App\Models\ManagerApproval;
use App\Models\Order;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

class VoidsReport extends Report
{
    public static function key(): string
    {
        return 'voids';
    }

    public static function title(): string
    {
        return 'Voids';
    }

    public static function group(): string
    {
        return 'Audit';
    }

    public static function description(): string
    {
        return 'Every voided order: when, who, who approved, and why.';
    }

    protected function sections(Period $period): array
    {
        $orders = Order::query()
            ->with(['voider', 'cashier', 'creator', 'customer'])
            ->whereNotNull('voided_at')
            ->whereBetween('voided_at', $period->range())
            ->orderBy('voided_at')
            ->get();

        $approvers = ManagerApproval::query()
            ->with('approver')
            ->where('action', ApprovalAction::VoidOrder)
            ->where('subject_type', (new Order)->getMorphClass())
            ->whereIn('subject_id', $orders->pluck('id'))
            ->get()
            ->keyBy('subject_id');

        $rows = $orders->map(fn (Order $o) => [
            'date' => $o->voided_at?->toDateTimeString(),
            'order' => $o->order_no,
            'slip' => $o->slip_no,
            'was' => $o->slip_no ? 'Paid' : 'Pending',
            'customer' => $o->customer?->name,
            'total' => (float) $o->total,
            'reason' => $o->void_reason,
            'by' => $o->voider?->name,
            'approved_by' => $approvers[$o->id]?->approver?->name,
        ])->all();

        return [new Section([
            Column::datetime('date', 'Voided'),
            Column::text('order', 'Order'),
            Column::text('slip', 'Slip'),
            Column::text('was', 'Was'),
            Column::text('customer', 'Customer'),
            Column::money('total', 'Total')->total(),
            Column::text('reason', 'Reason'),
            Column::text('by', 'Voided by'),
            Column::text('approved_by', 'Approved by'),
        ], $rows)];
    }
}
