<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the stock ledger. Append-only: rows are written by StockService and never
 * updated or deleted.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'type',
        'qty',
        'unit_cost',
        'balance_after',
        'reference_type',
        'reference_id',
        'remarks',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'qty' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'balance_after' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human document number of whatever caused this movement ("RCV-000003", "ORD-000120"…).
     */
    public function referenceLabel(): ?string
    {
        $reference = $this->reference;

        if ($reference === null) {
            return null;
        }

        foreach (['slip_no', 'return_no', 'order_no', 'receiving_no', 'adjustment_no', 'count_no'] as $attribute) {
            if (filled($reference->getAttribute($attribute))) {
                return $reference->getAttribute($attribute);
            }
        }

        return class_basename($reference).' #'.$reference->getKey();
    }
}
