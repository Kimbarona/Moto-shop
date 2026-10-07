<?php

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use App\Services\Numbering\SequenceService;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A part. qty_on_hand is a cache of the stock ledger and is deliberately NOT fillable:
 * only App\Services\Stock\StockService writes it (together with a stock movement).
 */
class Product extends Model
{
    use LogsChanges, SoftDeletes;

    protected $fillable = [
        'sku',
        'name',
        'part_number',
        'brand_id',
        'category_id',
        'unit_id',
        'description',
        'avg_cost',
        'srp',
        'reorder_level',
        'bin_location',
        'image',
        'is_universal',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'avg_cost' => 'decimal:4',
            'srp' => 'decimal:2',
            'reorder_level' => 'decimal:2',
            'qty_on_hand' => 'decimal:2',
            'is_universal' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (blank($product->sku)) {
                $product->sku = app(SequenceService::class)->next('sku');
            }
        });

        static::updating(function (Product $product) {
            if ($product->isDirty('qty_on_hand') && ! StockService::isWriting()) {
                throw new LogicException('qty_on_hand can only be changed through StockService.');
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(ProductFitment::class);
    }

    public function fitsModels(): BelongsToMany
    {
        return $this->belongsToMany(MotorcycleModel::class, 'product_fitments')->withPivot('notes')->withTimestamps();
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('products.is_active', true);
    }

    /**
     * Adds reserved_qty (parts on Pending orders) and available_qty columns.
     * Reserved is always computed, never stored, so releasing a reservation is automatic.
     */
    public function scopeWithStockLevels(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        $reserved = static::reservedSubquery();

        return $query
            ->selectSub($reserved, 'reserved_qty')
            ->selectRaw('products.qty_on_hand - ('.$reserved->toSql().') as available_qty', $reserved->getBindings());
    }

    /**
     * Parts that fit a motorcycle model, plus universal parts.
     */
    public function scopeFitsModel(Builder $query, int $motorcycleModelId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('products.is_universal', true)
            ->orWhereHas('fitments', fn (Builder $f) => $f->where('motorcycle_model_id', $motorcycleModelId)));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('products.name', 'like', $like)
            ->orWhere('products.sku', 'like', $like)
            ->orWhere('products.part_number', 'like', $like)
            ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', $like)));
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('products.qty_on_hand', '<=', 'products.reorder_level');
    }

    /**
     * SUM of part qty on Pending orders for the outer products row.
     */
    public static function reservedSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereColumn('order_items.product_id', 'products.id')
            ->where('order_items.line_type', 'part')
            ->where('orders.status', 'pending')
            ->selectRaw('COALESCE(SUM(order_items.qty), 0)');
    }

    /**
     * Reserved qty for this product (fresh query; call inside the stock lock when it matters).
     */
    public function reservedQty(?int $exceptOrderId = null): float
    {
        return (float) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $this->id)
            ->where('order_items.line_type', 'part')
            ->where('orders.status', 'pending')
            ->when($exceptOrderId, fn ($q) => $q->where('orders.id', '!=', $exceptOrderId))
            ->sum('order_items.qty');
    }

    public function availableQty(?int $exceptOrderId = null): float
    {
        return round((float) $this->qty_on_hand - $this->reservedQty($exceptOrderId), 2);
    }
}
