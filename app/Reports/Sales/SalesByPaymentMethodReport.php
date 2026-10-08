<?php

namespace App\Reports\Sales;

use App\Models\Payment;
use App\Models\SalesReturn;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use App\Services\Reports\SalesLedger;

/**
 * Money in (payments) and out (refunds) per method, plus every e-wallet / bank
 * reference no. for reconciliation. Labels only: there is no payment integration.
 */
class SalesByPaymentMethodReport extends Report
{
    public static function key(): string
    {
        return 'sales-by-payment-method';
    }

    public static function title(): string
    {
        return 'Sales by Payment Method';
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return 'Cash, e-wallet and bank totals, refunds paid out, and reference nos.';
    }

    protected function sections(Period $period): array
    {
        $methods = array_map(fn (array $row) => [
            ...$row,
            'type' => match ($row['type']) {
                'ewallet' => 'E-Wallet',
                'bank' => 'Bank',
                default => 'Cash',
            },
            'refunded' => $row['refunded'] > 0 ? -$row['refunded'] : 0.0,
        ], app(SalesLedger::class)->byPaymentMethod($period));

        $references = Payment::query()
            ->notVoided()
            ->with(['method', 'order'])
            ->whereBetween('paid_at', $period->range())
            ->whereHas('method', fn ($q) => $q->where('type', '!=', 'cash'))
            ->orderBy('paid_at')
            ->get()
            ->map(fn (Payment $p) => [
                'date' => $p->paid_at?->toDateTimeString(),
                'method' => $p->method?->name,
                'reference' => $p->reference_no,
                'document' => $p->order?->slip_no,
                'amount' => (float) $p->amount,
            ])
            ->concat(SalesReturn::query()
                ->with(['refundMethod', 'order'])
                ->where('refund_amount', '>', 0)
                ->whereBetween('date', $period->range())
                ->whereHas('refundMethod', fn ($q) => $q->where('type', '!=', 'cash'))
                ->get()
                ->map(fn (SalesReturn $r) => [
                    'date' => $r->date?->toDateTimeString(),
                    'method' => $r->refundMethod?->name,
                    'reference' => $r->refund_reference,
                    'document' => $r->return_no.' (refund)',
                    'amount' => -(float) $r->refund_amount,
                ]))
            ->sortBy('date')
            ->values()
            ->all();

        return [
            new Section([
                Column::text('method', 'Method'),
                Column::text('type', 'Type'),
                Column::int('payments', 'Payments')->total(),
                Column::money('received', 'Received')->total(),
                Column::money('refunded', 'Refunded')->total(),
                Column::money('net', 'Net')->total(),
            ], $methods),
            new Section([
                Column::datetime('date', 'Date'),
                Column::text('method', 'Method'),
                Column::text('reference', 'Reference no.'),
                Column::text('document', 'Slip / return'),
                Column::money('amount', 'Amount')->total(),
            ], $references, 'E-wallet & bank reference nos.'),
        ];
    }
}
