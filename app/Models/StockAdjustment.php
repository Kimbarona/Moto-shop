<?php

namespace App\Models;

use App\Enums\AdjustmentReason;
use App\Models\Concerns\IsInventoryDocument;
use App\Models\Concerns\LogsChanges;
use App\Services\Numbering\SequenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Manual stock correction with a reason and required remarks. Line qty is signed:
 * positive adds stock (adjustment_in), negative removes it (adjustment_out).
 */
class StockAdjustment extends Model
{
    use IsInventoryDocument, LogsChanges;

    protected $fillable = [
        'adjustment_no',
        'date',
        'reason',
        'remarks',
        'status',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reason' => AdjustmentReason::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockAdjustment $adjustment) {
            $adjustment->adjustment_no ??= app(SequenceService::class)->next('adjustment');
            $adjustment->created_by ??= auth()->id();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
