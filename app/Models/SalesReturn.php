<?php

namespace App\Models;

use App\Enums\ReturnReason;
use App\Enums\ReturnResolution;
use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A return against a paid order (table `returns`; "Return" is a reserved word in PHP).
 *
 *  - return_total: value of everything returned (what the customer paid for it)
 *  - refund resolution: refund_amount = return_total, paid out by refund_method
 *  - exchange resolution: return_total becomes credit on a new walk-in sale; if that
 *    sale costs less than the credit, the difference is paid out (refund_amount)
 *
 * Never deleted or edited after it is processed.
 */
class SalesReturn extends Model
{
    use LogsChanges;

    protected $table = 'returns';

    protected $fillable = [
        'return_no',
        'order_id',
        'date',
        'reason',
        'remarks',
        'resolution',
        'return_total',
        'refund_method_id',
        'refund_reference',
        'refund_amount',
        'approved_by',
        'processed_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'reason' => ReturnReason::class,
            'resolution' => ReturnResolution::class,
            'return_total' => 'decimal:2',
            'refund_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    public function refundMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'refund_method_id')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * The (non-voided) sale that used this return's exchange credit.
     */
    public function exchangeOrder(): HasOne
    {
        return $this->hasOne(Order::class, 'exchange_return_id')->where('orders.status', '!=', 'voided');
    }

    public function isExchange(): bool
    {
        return $this->resolution === ReturnResolution::Exchange;
    }

    /**
     * An exchange whose credit has not been used on a sale yet.
     */
    public function scopeUnusedExchange(Builder $query): Builder
    {
        return $query->where('resolution', ReturnResolution::Exchange)
            ->whereDoesntHave('exchangeOrder');
    }
}
