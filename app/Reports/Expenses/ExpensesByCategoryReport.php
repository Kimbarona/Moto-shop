<?php

namespace App\Reports\Expenses;

use App\Models\Expense;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

class ExpensesByCategoryReport extends Report
{
    public static function key(): string
    {
        return 'expenses';
    }

    public static function title(): string
    {
        return 'Expenses by Category';
    }

    public static function group(): string
    {
        return 'Expenses';
    }

    public static function description(): string
    {
        return 'Expense totals per category and every expense (voided ones excluded).';
    }

    protected function sections(Period $period): array
    {
        $expenses = Expense::query()
            ->notVoided()
            ->with(['category', 'paymentMethod', 'creator'])
            ->whereBetween('date', [$period->from->toDateString(), $period->until->toDateString()])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $total = (float) $expenses->sum('amount');

        $byCategory = $expenses->groupBy(fn (Expense $e) => $e->category?->name ?? 'Uncategorized')
            ->map(fn ($group, $name) => [
                'category' => $name,
                'count' => $group->count(),
                'amount' => round((float) $group->sum('amount'), 2),
                'share' => static::pct((float) $group->sum('amount'), $total),
            ])->sortByDesc('amount')->values()->all();

        $list = $expenses->map(fn (Expense $e) => [
            'date' => $e->date?->toDateString(),
            'category' => $e->category?->name,
            'description' => $e->description,
            'method' => $e->paymentMethod?->name,
            'reference' => $e->reference_no,
            'amount' => (float) $e->amount,
            'by' => $e->creator?->name,
        ])->all();

        return [
            new Section([
                Column::text('category', 'Category'),
                Column::int('count', 'Entries')->total(),
                Column::money('amount', 'Amount')->total(),
                Column::percent('share', 'Share'),
            ], $byCategory),
            new Section([
                Column::date('date', 'Date'),
                Column::text('category', 'Category'),
                Column::text('description', 'Description'),
                Column::text('method', 'Paid by'),
                Column::text('reference', 'Ref no.'),
                Column::money('amount', 'Amount')->total(),
                Column::text('by', 'Entered by'),
            ], $list, 'Expenses'),
        ];
    }
}
