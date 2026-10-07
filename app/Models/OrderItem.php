<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\LineType;
use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order line. description / original_price / unit_price are snapshots taken when the
 * line was added; unit_cost and commission_amount are snapshots taken at payment.
 *
 * line_total = qty × unit_price − discount (line discount only).
 * Net amount actually charged = line_total − discount_share (allocated order discount).
 */
class OrderItem extends Model
{
    use LogsChanges;

    protected $fillable = [
        'order_id',
        'line_type',
        'product_id',
        'service_id',
        'service_package_id',
        'description',
        'qty',
        'original_price',
        'unit_price',
        'discount_type',
        'discount_value',
        'discount',
        'line_total',
        'discount_share',
        'unit_cost',
        'mechanic_id',
        'commission_amount',
        'qty_returned',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => LineType::class,
            'discount_type' => DiscountType::class,
            'qty' => 'decimal:2',
            'original_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'discount_share' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'qty_returned' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected function auditExcept(): array
    {
        return ['line_total', 'discount_share', 'sort_order'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id')->withTrashed();
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class)->withTrashed();
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function isPart(): bool
    {
        return $this->line_type === LineType::Part;
    }

    public function isLabor(): bool
    {
        return $this->line_type === LineType::Labor;
    }

    public function gross(): float
    {
        return round((float) $this->qty * (float) $this->unit_price, 2);
    }

    /**
     * What the customer actually paid for this line, after line and order discounts.
     */
    public function netAmount(): float
    {
        return round((float) $this->line_total - (float) $this->discount_share, 2);
    }

    public function returnableQty(): float
    {
        return round((float) $this->qty - (float) $this->qty_returned, 2);
    }
}
