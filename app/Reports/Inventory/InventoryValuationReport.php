<?php

namespace App\Reports\Inventory;

use App\Models\Product;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use Illuminate\Support\Collection;

/**
 * Stock on hand at weighted average cost (admins only: it is all cost).
 */
class InventoryValuationReport extends Report
{
    public static function key(): string
    {
        return 'inventory-valuation';
    }

    public static function title(): string
    {
        return 'Inventory Valuation';
    }

    public static function group(): string
    {
        return 'Inventory';
    }

    public static function description(): string
    {
        return 'On hand × average cost, and at selling price, per part and category.';
    }

    public static function usesDates(): bool
    {
        return false;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows()
    {
        return Product::query()
            ->with(['category', 'unit'])
            ->where('qty_on_hand', '!=', 0)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category?->name ?? 'Uncategorized',
                'qty' => (float) $p->qty_on_hand,
                'unit' => $p->unit?->abbreviation,
                'avg_cost' => round((float) $p->avg_cost, 2),
                'value' => round((float) $p->qty_on_hand * (float) $p->avg_cost, 2),
                'srp' => (float) $p->srp,
                'retail_value' => round((float) $p->qty_on_hand * (float) $p->srp, 2),
            ]);
    }

    protected function summary(Period $period): array
    {
        $rows = $this->rows();

        return [
            'Parts in stock' => [$rows->count(), 'int'],
            'Value at cost' => [$rows->sum('value'), 'money', true],
            'Value at SRP' => [$rows->sum('retail_value'), 'money'],
        ];
    }

    protected function sections(Period $period): array
    {
        $rows = $this->rows();

        $byCategory = $rows->groupBy('category')
            ->map(fn ($group, $category) => [
                'category' => $category,
                'items' => $group->count(),
                'value' => round($group->sum('value'), 2),
                'retail_value' => round($group->sum('retail_value'), 2),
            ])->sortByDesc('value')->values()->all();

        return [
            new Section([
                Column::text('category', 'Category'),
                Column::int('items', 'Parts')->total(),
                Column::money('value', 'Value at cost')->total()->costOnly(),
                Column::money('retail_value', 'Value at SRP')->total(),
            ], $byCategory, 'By category'),
            new Section([
                Column::text('sku', 'SKU'),
                Column::text('name', 'Part'),
                Column::text('category', 'Category'),
                Column::qty('qty', 'On hand'),
                Column::text('unit', 'Unit'),
                Column::money('avg_cost', 'Avg cost')->costOnly(),
                Column::money('value', 'Value at cost')->total()->costOnly(),
                Column::money('srp', 'SRP'),
                Column::money('retail_value', 'Value at SRP')->total(),
            ], $rows->values()->all(), 'Parts'),
        ];
    }
}
