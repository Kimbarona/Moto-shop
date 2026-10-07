<?php

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment on an order. The POS takes one per order today, but the table holds many so
 * split payment can be added later. Payments are voided (voided_at), never deleted.
 */
class Payment extends Model
{
    use LogsChanges;

    protected $fillable = [
        'order_id',
        'payment_method_id',
        'amount',
        'amount_tendered',
        'change_due',
        'reference_no',
        'received_by',
        'paid_at',
        'voided_at',
        'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_due' => 'decimal:2',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id')->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('payments.voided_at');
    }
}
