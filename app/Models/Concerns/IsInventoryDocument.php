<?php

namespace App\Models\Concerns;

use App\Enums\DocumentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock In, Adjustments and Physical Counts: drafts are editable, posting writes the
 * stock movements and locks the document. Documents are cancelled, never deleted.
 *
 * @property DocumentStatus $status
 */
trait IsInventoryDocument
{
    public function initializeIsInventoryDocument(): void
    {
        $this->mergeCasts([
            'date' => 'date',
            'status' => DocumentStatus::class,
            'posted_at' => 'datetime',
        ]);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::Posted;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Draft);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Posted);
    }
}
