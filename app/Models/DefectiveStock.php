<?php

namespace App\Models;

use App\Enums\DefectiveStatus;
use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Defective items kept OUT of sellable stock until they are returned to the supplier
 * or written off. Sources: a customer return (return_item_id) or a part pulled off the
 * shelf (a defective_out movement).
 */
class DefectiveStock extends Model
{
    use LogsChanges;

    protected $fillable = [
        'product_id',
        'qty',
        'unit_cost',
        'return_item_id',
        'status',
        'supplier_id',
        'supplier_reference',
        'notes',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'status' => DefectiveStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isPending(): bool
    {
        return $this->status === DefectiveStatus::Pending;
    }

    public function value(): float
    {
        return round((float) $this->qty * (float) $this->unit_cost, 2);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DefectiveStatus::Pending);
    }
}
