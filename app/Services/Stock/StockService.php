<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Settings\InventorySettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The ONLY writer of products.qty_on_hand. Every change writes a stock_movements row
 * (with the running balance) in the same transaction, on a row-locked product.
 *
 * Callers that touch several products should call lockProducts() first so rows are
 * always locked in ascending id order (no deadlocks between concurrent postings).
 */
class StockService
{
    /**
     * Set while this service writes qty_on_hand; Product refuses any other write.
     */
    private static bool $writing = false;

    public function __construct(private readonly InventorySettings $settings) {}

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * Lock product rows FOR UPDATE in a stable order and return them keyed by id.
     *
     * @param  iterable<int>  $productIds
     * @return Collection<int, Product>
     */
    public function lockProducts(iterable $productIds): Collection
    {
        $this->ensureTransaction();

        $ids = collect($productIds)->map(fn ($id) => (int) $id)->unique()->sort()->values();

        return Product::withTrashed()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Receive stock and update the weighted average cost:
     * new_avg = (on_hand × avg_cost + qty × unit_cost) ÷ (on_hand + qty)
     */
    public function stockIn(Product|int $product, float $qty, float $unitCost, ?Model $reference = null, ?string $remarks = null, ?User $user = null): StockMovement
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Received quantity must be more than zero.']);
        }

        return $this->move($product, MovementType::StockIn, $qty, $unitCost, $reference, $remarks, $user, updateAverageCost: true);
    }

    /**
     * Record a signed movement. Positive adds stock, negative removes it.
     * $unitCost defaults to the product's current average cost.
     *
     * @throws ValidationException when stock would go negative and that is not allowed
     */
    public function move(
        Product|int $product,
        MovementType $type,
        float $qty,
        ?float $unitCost = null,
        ?Model $reference = null,
        ?string $remarks = null,
        ?User $user = null,
        bool $updateAverageCost = false,
    ): StockMovement {
        $this->ensureTransaction();

        $qty = round($qty, 2);

        if ($qty == 0.0) {
            throw new LogicException('A stock movement needs a non-zero quantity.');
        }

        /** @var Product $locked */
        $locked = Product::withTrashed()->whereKey($product instanceof Product ? $product->getKey() : $product)
            ->lockForUpdate()
            ->firstOrFail();

        $onHand = (float) $locked->qty_on_hand;
        $balance = round($onHand + $qty, 2);

        if ($qty < 0 && $balance < 0 && ! $this->settings->allow_negative_stock) {
            throw ValidationException::withMessages([
                'qty' => sprintf(
                    'Not enough stock for %s: %s on hand, %s needed.',
                    $locked->name,
                    $this->format($onHand),
                    $this->format(abs($qty)),
                ),
            ]);
        }

        $unitCost ??= (float) $locked->avg_cost;

        if ($updateAverageCost && $qty > 0) {
            $locked->avg_cost = $this->weightedAverage($onHand, (float) $locked->avg_cost, $qty, $unitCost);
        }

        self::$writing = true;

        try {
            $locked->qty_on_hand = $balance;
            $locked->save();
        } finally {
            self::$writing = false;
        }

        $movement = StockMovement::create([
            'product_id' => $locked->id,
            'type' => $type,
            'qty' => $qty,
            'unit_cost' => round($unitCost, 4),
            'balance_after' => $balance,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'remarks' => $remarks,
            'user_id' => $user?->id ?? auth()->id(),
        ]);

        // Keep a caller-held instance in sync so it can be reused in the same request.
        if ($product instanceof Product) {
            $product->setRawAttributes($locked->getAttributes(), true);
        }

        return $movement;
    }

    /**
     * Weighted average; stock at or below zero has no cost weight, so the new cost wins.
     */
    public function weightedAverage(float $onHand, float $avgCost, float $qty, float $unitCost): float
    {
        $existing = max(0.0, $onHand);

        if ($existing + $qty <= 0) {
            return round($unitCost, 4);
        }

        return round(($existing * $avgCost + $qty * $unitCost) / ($existing + $qty), 4);
    }

    private function ensureTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock changes must run inside a database transaction.');
        }
    }

    private function format(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2), '0'), '.');
    }
}
