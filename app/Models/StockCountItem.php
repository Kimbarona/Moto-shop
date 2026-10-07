<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * system_qty is the on-hand qty when the count was opened; it is refreshed to the live
 * qty when the count is posted. Variance = counted − system.
 */
class StockCountItem extends Model
{
    protected $fillable = [
        'stock_count_id',
        'product_id',
        'system_qty',
        'counted_qty',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:2',
            'counted_qty' => 'decimal:2',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variance(): ?float
    {
        return $this->counted_qty === null ? null : round((float) $this->counted_qty - (float) $this->system_qty, 2);
    }

    public function varianceValue(): ?float
    {
        $variance = $this->variance();

        return $variance === null ? null : round($variance * (float) $this->unit_cost, 2);
    }
}
