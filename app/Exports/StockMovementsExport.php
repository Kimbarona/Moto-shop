<?php

namespace App\Exports;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Stock card / stock movement ledger export. Cost columns only for roles that may see cost.
 */
class StockMovementsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  Builder<StockMovement>  $query
     */
    public function __construct(private readonly Builder $query, private readonly bool $includeCost) {}

    public function query(): Builder
    {
        return $this->query->with(['product', 'user'])->reorder()->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_values(array_filter([
            'Date', 'SKU', 'Product', 'Type', 'Qty', 'Balance after',
            $this->includeCost ? 'Unit cost' : null,
            $this->includeCost ? 'Value' : null,
            'Remarks', 'User',
        ]));
    }

    /**
     * @param  StockMovement  $movement
     * @return list<mixed>
     */
    public function map($movement): array
    {
        $row = [
            $movement->created_at?->format('M d, Y h:i A'),
            $movement->product?->sku,
            $movement->product?->name,
            $movement->type->getLabel(),
            (float) $movement->qty,
            (float) $movement->balance_after,
        ];

        if ($this->includeCost) {
            $row[] = (float) $movement->unit_cost;
            $row[] = round((float) $movement->qty * (float) $movement->unit_cost, 2);
        }

        $row[] = $movement->remarks;
        $row[] = $movement->user?->name;

        return $row;
    }
}
