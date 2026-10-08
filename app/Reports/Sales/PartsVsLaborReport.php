<?php

namespace App\Reports\Sales;

use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use App\Services\Reports\SalesLedger;

class PartsVsLaborReport extends Report
{
    public static function key(): string
    {
        return 'parts-vs-labor';
    }

    public static function title(): string
    {
        return 'Parts vs Labor Revenue';
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return 'How much of net sales came from parts and how much from labor, per day.';
    }

    protected function summary(Period $period): array
    {
        $s = app(SalesLedger::class)->summary($period);
        $parts = $s['parts'] - $s['parts_refunds'];
        $labor = $s['labor'] - $s['labor_refunds'];

        return [
            'Parts (net)' => [$parts, 'money'],
            'Labor (net)' => [$labor, 'money'],
            'Labor share' => [static::pct($labor, $parts + $labor), 'percent'],
        ];
    }

    protected function sections(Period $period): array
    {
        $rows = collect(app(SalesLedger::class)->daily($period))
            ->map(function (array $day, string $date) {
                $parts = round($day['parts'] - $day['parts_refunds'], 2);
                $labor = round($day['labor'] - $day['labor_refunds'], 2);

                return [
                    'date' => $date,
                    'parts' => $parts,
                    'labor' => $labor,
                    'total' => round($parts + $labor, 2),
                    'labor_share' => static::pct($labor, $parts + $labor),
                ];
            })
            ->values()
            ->all();

        return [new Section([
            Column::date('date', 'Date'),
            Column::money('parts', 'Parts')->total(),
            Column::money('labor', 'Labor')->total(),
            Column::money('total', 'Total')->total(),
            Column::percent('labor_share', 'Labor %'),
        ], $rows)];
    }
}
