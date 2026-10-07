<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\RefundStatus;
use App\Enums\ServiceStatus;
use App\Models\Concerns\LogsChanges;
use App\Services\Numbering\SequenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A sale. Pending orders reserve their parts (reserved qty is computed, never stored);
 * payment deducts stock and locks the order; nothing is ever deleted (void / return).
 */
class Order extends Model
{
    use LogsChanges;

    protected $fillable = [
        'order_no',
        'slip_no',
        'type',
        'customer_id',
        'customer_motorcycle_id',
        'odometer_in',
        'complaint',
        'status',
        'service_status',
        'refund_status',
        'discount_type',
        'discount_value',
        'subtotal',
        'discount_total',
        'vatable',
        'vat_amount',
        'total',
        'exchange_return_id',
        'return_credit',
        'done_at',
        'paid_at',
        'cashier_id',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'service_status' => ServiceStatus::class,
            'refund_status' => RefundStatus::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'odometer_in' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'vatable' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'return_credit' => 'decimal:2',
            'done_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->order_no ??= app(SequenceService::class)->next('order');
            $order->status ??= OrderStatus::Pending;
            $order->refund_status ??= RefundStatus::None;
            $order->created_by ??= auth()->id();
        });
    }

    protected function auditExcept(): array
    {
        // Totals are recalculated on every line change; the line changes themselves are logged.
        return ['subtotal', 'discount_total', 'vatable', 'vat_amount', 'total'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function motorcycle(): BelongsTo
    {
        return $this->belongsTo(CustomerMotorcycle::class, 'customer_motorcycle_id')->withTrashed();
    }

    public function mechanics(): BelongsToMany
    {
        return $this->belongsToMany(Mechanic::class, 'order_mechanic')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid;
    }

    public function isVoided(): bool
    {
        return $this->status === OrderStatus::Voided;
    }

    public function isService(): bool
    {
        return $this->type === OrderType::Service;
    }

    /**
     * Amount the customer still has to pay (total less exchange credit).
     */
    public function amountDue(): float
    {
        return max(0.0, round((float) $this->total - (float) $this->return_credit, 2));
    }

    /**
     * Is $user one of the mechanics assigned to this order?
     */
    public function isAssignedTo(User $user): bool
    {
        return $this->mechanics()->where('mechanics.user_id', $user->id)->exists();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('orders.status', OrderStatus::Pending);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('orders.status', OrderStatus::Paid);
    }
}
