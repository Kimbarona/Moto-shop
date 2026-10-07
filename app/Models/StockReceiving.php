<?php

namespace App\Models;

use App\Models\Concerns\IsInventoryDocument;
use App\Models\Concerns\LogsChanges;
use App\Services\Numbering\SequenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Stock In (receiving from a supplier). Posting creates stock_in movements and updates
 * the weighted average cost of each product.
 */
class StockReceiving extends Model
{
    use IsInventoryDocument, LogsChanges;

    protected $fillable = [
        'receiving_no',
        'date',
        'supplier_id',
        'reference_no',
        'notes',
        'status',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockReceiving $receiving) {
            $receiving->receiving_no ??= app(SequenceService::class)->next('receiving');
            $receiving->created_by ??= auth()->id();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReceivingItem::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function total(): float
    {
        return round($this->items->sum(fn (StockReceivingItem $item) => (float) $item->qty * (float) $item->unit_cost), 2);
    }
}
