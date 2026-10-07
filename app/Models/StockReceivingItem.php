<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReceivingItem extends Model
{
    protected $fillable = [
        'stock_receiving_id',
        'product_id',
        'qty',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(StockReceiving::class, 'stock_receiving_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
