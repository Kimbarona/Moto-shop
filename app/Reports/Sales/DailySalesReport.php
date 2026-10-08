<?php

namespace App\Reports\Sales;

use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use App\Services\Reports\SalesLedger;

class DailySalesReport extends Report
{
    public static function key(): string
    {
        return 'daily-sales';
    }

    public static function title(): string
    {
        return 'Daily Sales Summary';
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return 'Gross, discounts, refunds, net and VAT per day.';
    }

    protected function summary(Period $period): array
    {
        $s = app(SalesLedger::class)->summary($period);

        return [
            'Orders paid' => [$s['orders'], 'int'],
            'Gross sales' => [$s['gross'], 'money'],
            'Discounts' => [$s['discounts'], 'money'],
            'Refunds' => [$s['refunds'], 'money'],
            'Net sales' => [$s['net'], 'money'],
            'VAT' => [$s['vat'], 'money'],
            'Gross profit' => [$s['gross_profit'], 'money', true],
        ];
    }

    protected function sections(Period $period): array
    {
        $rows = collect(app(SalesLedger::class)->daily($period))
            ->map(fn (array $day, string $date) => [
                'date' => $date,
                'orders' => $day['orders'],
                'gross' => $day['gross'],
                'discounts' => $day['discounts'],
                'sales' => $day['sales'],
                'refunds' => $day['refunds'] > 0 ? -$day['refunds'] : 0.0,
                'net' => $day['net'],
                'vat' => $day['vat'],
                'gross_profit' => $day['gross_profit'],
            ])
            ->values()
            ->all();

        return [new Section([
            Column::date('date', 'Date'),
            Column::int('orders', 'Orders')->total(),
            Column::money('gross', 'Gross')->total(),
            Column::money('discounts', 'Discounts')->total(),
            Column::money('sales', 'Sales')->total(),
            Column::money('refunds', 'Refunds')->total(),
            Column::money('net', 'Net sales')->total(),
            Column::money('vat', 'VAT')->total(),
            Column::money('gross_profit', 'Gross profit')->total()->costOnly(),
        ], $rows)];
    }
}
