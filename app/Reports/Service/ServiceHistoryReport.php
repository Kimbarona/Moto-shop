<?php

namespace App\Reports\Service;

use App\Models\Order;
use App\Models\OrderItem;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

/**
 * Paid service jobs with the customer, bike, odometer, and what was done.
 */
class ServiceHistoryReport extends Report
{
    public static function key(): string
    {
        return 'service-history';
    }

    public static function title(): string
    {
        return 'Customer & Motorcycle Service History';
    }

    public static function group(): string
    {
        return 'Service';
    }

    public static function description(): string
    {
        return 'Every paid service job: customer, plate, odometer, parts and labor.';
    }

    protected function sections(Period $period): array
    {
        $rows = Order::query()
            ->paid()
            ->where('type', 'service')
            ->whereBetween('paid_at', $period->range())
            ->with(['customer', 'motorcycle.motorcycleModel.make', 'items', 'mechanics'])
            ->orderBy('paid_at')
            ->get()
            ->map(fn (Order $o) => [
                'date' => $o->paid_at?->toDateTimeString(),
                'slip' => $o->slip_no,
                'customer' => $o->customer?->name,
                'mobile' => $o->customer?->mobile,
                'plate' => $o->motorcycle?->plate_no,
                'model' => $o->motorcycle?->motorcycleModel?->full_name,
                'odometer' => $o->odometer_in,
                'work' => $o->items->map(fn (OrderItem $i) => ($i->isPart() ? rtrim(rtrim(number_format((float) $i->qty, 2), '0'), '.').'× ' : '').$i->description)->implode('; '),
                'mechanics' => $o->mechanics->pluck('name')->implode(', '),
                'total' => (float) $o->total,
            ])
            ->all();

        return [new Section([
            Column::datetime('date', 'Date'),
            Column::text('slip', 'Slip'),
            Column::text('customer', 'Customer'),
            Column::text('mobile', 'Mobile'),
            Column::text('plate', 'Plate'),
            Column::text('model', 'Motorcycle'),
            Column::int('odometer', 'Odometer (km)'),
            Column::text('work', 'Parts & labor'),
            Column::text('mechanics', 'Mechanic'),
            Column::money('total', 'Total')->total(),
        ], $rows)];
    }
}
