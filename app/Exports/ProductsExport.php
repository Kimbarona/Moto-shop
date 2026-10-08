<?php

namespace App\Exports;

use App\Models\Product;
use App\Services\Catalog\ProductImportService;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Products in the same columns the importer reads (so an export can be edited and
 * imported back), plus read-only stock columns. Cost only for roles that may see it.
 */
class ProductsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  Builder<Product>|null  $query
     */
    public function __construct(private readonly bool $includeCost, private readonly ?Builder $query = null, private readonly bool $template = false) {}

    public function query(): Builder
    {
        $query = $this->query ?? Product::query();

        return $query->with(['category', 'brand', 'unit', 'fitsModels.make'])->withStockLevels()->reorder()->orderBy('products.name')
            ->when($this->template, fn (Builder $q) => $q->limit(2));
    }

    public function headings(): array
    {
        return [
            ...ProductImportService::COLUMNS,
            'on_hand (read only)',
            'reserved (read only)',
            'available (read only)',
            ...($this->includeCost ? ['avg_cost (read only)'] : []),
        ];
    }

    /**
     * @param  Product  $product
     */
    public function map($product): array
    {
        return [
            $product->sku,
            $product->name,
            $product->part_number,
            $product->category?->name,
            $product->brand?->name,
            $product->unit?->abbreviation,
            (float) $product->srp,
            (float) $product->reorder_level,
            $product->bin_location,
            $product->is_universal ? 'yes' : 'no',
            $product->is_active ? 'yes' : 'no',
            $product->fitsModels->map(fn ($model) => $model->full_name)->implode('; '),
            $product->description,
            (float) $product->qty_on_hand,
            round((float) $product->reserved_qty, 2),
            round((float) $product->available_qty, 2),
            ...($this->includeCost ? [round((float) $product->avg_cost, 2)] : []),
        ];
    }
}
