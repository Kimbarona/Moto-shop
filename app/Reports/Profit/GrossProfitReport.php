<?php

namespace App\Reports\Profit;

use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use App\Services\Reports\SalesLedger;

/**
 * Net sales (without VAT) − COGS from the unit_cost snapshots taken at payment.
 */
class GrossProfitReport extends Report
{
    public static function key(): string
    {
        return 'gross-profit';
    }

    public static function title(): string
    {
        return 'Gross Profit';
    }

    public static function group(): string
    {
        return 'Profit';
    }

    public static function description(): string
    {
        return 'Net sales less cost of parts sold (cost frozen at the time of sale).';
    }

    protected function summary(Period $period): array
    {
        $s = app(SalesLedger::class)->summary($period);

        return [
            'Net sales (no VAT)' => [$s['net_ex_vat'], 'money'],
            'Cost of goods sold' => [$s['cogs'], 'money'],
            'Gross profit' => [$s['gross_profit'], 'money'],
            'Margin' => [$s['margin'], 'percent'],
        ];
    }

    protected function sections(Period $period): array
    {
        $ledger = app(SalesLedger::class);

        $daily = collect($ledger->daily($period))
            ->map(fn (array $d, string $date) => [
                'date' => $date,
                'net_ex_vat' => $d['net_ex_vat'],
                'cogs' => $d['cogs'],
                'gross_profit' => $d['gross_profit'],
                'margin' => $d['margin'],
            ])->values()->all();

        $categories = array_map(fn (array $row) => [
            'category' => $row['category'],
            'net_ex_vat' => $row['net_ex_vat'],
            'cogs' => $row['cogs'],
            'gross_profit' => $row['gross_profit'],
            'margin' => static::pct($row['gross_profit'], $row['net_ex_vat']),
        ], $ledger->byCategory($period));

        return [
            new Section([
                Column::date('date', 'Date'),
                Column::money('net_ex_vat', 'Net sales (no VAT)')->total(),
                Column::money('cogs', 'COGS')->total(),
                Column::money('gross_profit', 'Gross profit')->total(),
                Column::percent('margin', 'Margin'),
            ], $daily),
            new Section([
                Column::text('category', 'Category'),
                Column::money('net_ex_vat', 'Net sales (no VAT)')->total(),
                Column::money('cogs', 'COGS')->total(),
                Column::money('gross_profit', 'Gross profit')->total(),
                Column::percent('margin', 'Margin'),
            ], $categories, 'By category'),
        ];
    }
}
