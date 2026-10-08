<?php

namespace App\Reports\Inventory;

use App\Enums\DefectiveStatus;
use App\Models\DefectiveStock;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

class DefectiveStockReport extends Report
{
    public static function key(): string
    {
        return 'defective-stock';
    }

    public static function title(): string
    {
        return 'Defective Stock';
    }

    public static function group(): string
    {
        return 'Inventory';
    }

    public static function description(): string
    {
        return 'Defective items logged in the period, where they came from and what happened.';
    }

    public static function ability(): string
    {
        return 'view-inventory-reports';
    }

    public static function optionFields(): array
    {
        return [
            'status' => [
                'label' => 'Status',
                'type' => 'select',
                'options' => ['all' => 'All', ...DefectiveStatus::options()],
                'default' => 'all',
            ],
        ];
    }

    protected function sections(Period $period): array
    {
        $rows = DefectiveStock::query()
            ->with(['product', 'supplier', 'resolver'])
            ->whereBetween('created_at', $period->range())
            ->when($this->option('status') !== 'all', fn ($q) => $q->where('status', $this->option('status')))
            ->orderBy('created_at')
            ->get()
            ->map(fn (DefectiveStock $d) => [
                'date' => $d->created_at?->toDateTimeString(),
                'sku' => $d->product?->sku,
                'name' => $d->product?->name,
                'qty' => (float) $d->qty,
                'source' => $d->return_item_id ? 'Customer return' : 'Pulled from shelf',
                'status' => $d->status->getLabel(),
                'supplier' => $d->supplier?->name,
                'value' => $d->value(),
                'notes' => $d->notes,
            ])
            ->all();

        return [new Section([
            Column::datetime('date', 'Date'),
            Column::text('sku', 'SKU'),
            Column::text('name', 'Part'),
            Column::qty('qty', 'Qty')->total(),
            Column::text('source', 'Source'),
            Column::text('status', 'Status'),
            Column::text('supplier', 'Returned to'),
            Column::money('value', 'Value at cost')->total()->costOnly(),
            Column::text('notes', 'Notes'),
        ], $rows)];
    }
}
