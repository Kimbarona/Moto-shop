<?php

namespace App\Models;

use App\Enums\Disposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One returned line. unit_cost is the original sale's cost snapshot (stock comes back
 * at what it left at); amount is what the customer paid for this qty.
 */
class ReturnItem extends Model
{
    protected $fillable = [
        'return_id',
        'order_item_id',
        'qty',
        'disposition',
        'unit_cost',
        'amount',
        'commission_reversed',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'disposition' => Disposition::class,
            'unit_cost' => 'decimal:4',
            'amount' => 'decimal:2',
            'commission_reversed' => 'decimal:2',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'return_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function defectiveStock(): HasOne
    {
        return $this->hasOne(DefectiveStock::class);
    }
}
