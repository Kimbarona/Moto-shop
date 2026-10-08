<?php

namespace App\Reports\Profit;

use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use App\Services\Reports\SalesLedger;
use Illuminate\Support\Facades\DB;

/**
 * Simple P&L: gross profit − expenses (voided expenses excluded).
 */
class ProfitAndLossReport extends Report
{
    public static function key(): string
    {
        return 'profit-and-loss';
    }

    public static function title(): string
    {
        return 'Simple Profit & Loss';
    }

    public static function group(): string
    {
        return 'Profit';
    }

    public static function description(): string
    {
        return 'Gross profit less expenses for the period.';
    }

    /**
     * @return array<string, float>
     */
    private function expenses(Period $period): array
    {
        return DB::table('expenses')
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->whereNull('expenses.voided_at')
            ->whereBetween('expenses.date', [$period->from->toDateString(), $period->until->toDateString()])
            ->groupBy('expense_categories.name')
            ->orderBy('expense_categories.name')
            ->selectRaw('expense_categories.name, SUM(expenses.amount) as amount')
            ->pluck('amount', 'name')
            ->map(fn ($amount) => round((float) $amount, 2))
            ->all();
    }

    protected function summary(Period $period): array
    {
        $s = app(SalesLedger::class)->summary($period);
        $expenses = array_sum($this->expenses($period));

        return [
            'Gross profit' => [$s['gross_profit'], 'money'],
            'Expenses' => [$expenses, 'money'],
            'Net income' => [round($s['gross_profit'] - $expenses, 2), 'money'],
        ];
    }

    protected function sections(Period $period): array
    {
        $s = app(SalesLedger::class)->summary($period);
        $expenses = $this->expenses($period);
        $totalExpenses = round(array_sum($expenses), 2);

        $line = fn (string $label, ?float $amount, bool $strong = false) => ['line' => $strong ? strtoupper($label) : $label, 'amount' => $amount];

        $rows = [
            $line('Sales', $s['sales']),
            $line('Less: refunds', -$s['refunds']),
            $line('Net sales', $s['net'], true),
            $line('Less: VAT', -$s['vat']),
            $line('Net sales without VAT', $s['net_ex_vat'], true),
            $line('Less: cost of goods sold', -$s['cogs']),
            $line('Gross profit', $s['gross_profit'], true),
            ...array_map(fn ($name, $amount) => $line('Less: '.$name, -$amount), array_keys($expenses), $expenses),
            $line('Total expenses', -$totalExpenses, true),
            $line('Net income', round($s['gross_profit'] - $totalExpenses, 2), true),
        ];

        return [new Section([
            Column::text('line', ''),
            Column::money('amount', 'Amount'),
        ], $rows, showTotals: false)];
    }
}
