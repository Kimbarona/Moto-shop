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
 * Physical count session (all products or one category). Posting writes a
 * count_variance movement for every counted line whose qty differs from the system.
 */
class StockCount extends Model
{
    use IsInventoryDocument, LogsChanges;

    protected $fillable = [
        'count_no',
        'date',
        'category_id',
        'notes',
        'status',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockCount $count) {
            $count->count_no ??= app(SequenceService::class)->next('count');
            $count->created_by ??= auth()->id();
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
