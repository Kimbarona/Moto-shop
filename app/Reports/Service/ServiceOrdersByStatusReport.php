<?php

namespace App\Reports\Service;

use App\Models\Order;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

/**
 * Service orders opened in the period, by where they are now.
 */
class ServiceOrdersByStatusReport extends Report
{
    public static function key(): string
    {
        return 'service-orders';
    }

    public static function title(): string
    {
        return 'Service Orders by Status';
    }

    public static function group(): string
    {
        return 'Service';
    }

    public static function description(): string
    {
        return 'Service orders opened in the period: waiting, in progress, ready, paid, voided.';
    }

    private static function stage(Order $order): string
    {
        if (! $order->isPending()) {
            return $order->status->getLabel();
        }

        return $order->service_status?->getLabel() ?? 'Pending';
    }

    protected function sections(Period $period): array
    {
        $orders = Order::query()
            ->where('type', 'service')
            ->whereBetween('created_at', $period->range())
            ->with(['customer', 'motorcycle.motorcycleModel.make', 'mechanics'])
            ->orderBy('created_at')
            ->get();

        $byStatus = $orders->groupBy(fn (Order $o) => static::stage($o))
            ->map(fn ($group, $stage) => [
                'status' => $stage,
                'orders' => $group->count(),
                'value' => round((float) $group->sum('total'), 2),
            ])->values()->all();

        $list = $orders->map(fn (Order $o) => [
            'opened' => $o->created_at?->toDateTimeString(),
            'order' => $o->slip_no ?? $o->order_no,
            'customer' => $o->customer?->name,
            'plate' => $o->motorcycle?->plate_no,
            'model' => $o->motorcycle?->motorcycleModel?->full_name,
            'mechanics' => $o->mechanics->pluck('name')->implode(', '),
            'status' => static::stage($o),
            'hours' => $o->isPending() ? round($o->created_at->diffInMinutes(now()) / 60, 1) : null,
            'total' => (float) $o->total,
        ])->all();

        return [
            new Section([
                Column::text('status', 'Status'),
                Column::int('orders', 'Orders')->total(),
                Column::money('value', 'Value')->total(),
            ], $byStatus),
            new Section([
                Column::datetime('opened', 'Opened'),
                Column::text('order', 'Order / slip'),
                Column::text('customer', 'Customer'),
                Column::text('plate', 'Plate'),
                Column::text('model', 'Motorcycle'),
                Column::text('mechanics', 'Mechanic'),
                Column::text('status', 'Status'),
                Column::qty('hours', 'Hours open'),
                Column::money('total', 'Total')->total(),
            ], $list, 'Orders'),
        ];
    }
}
