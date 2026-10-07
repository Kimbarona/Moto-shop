<?php

namespace App\Services\Stock;

use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Physical count: open a session (all products or one category) → enter counted qty →
 * review variance → post. Posting compares against the LIVE on-hand qty, so sales made
 * while counting are not double-counted; post as soon as counting is finished.
 */
class PhysicalCountService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  array{date: string, category_id?: int|null, notes?: string|null}  $header
     */
    public function open(array $header, User $user): StockCount
    {
        return DB::transaction(function () use ($header, $user) {
            $count = StockCount::create([...$header, 'status' => DocumentStatus::Draft, 'created_by' => $user->id]);

            $products = Product::query()
                ->active()
                ->when($header['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
                ->orderBy('name')
                ->get(['id', 'qty_on_hand', 'avg_cost']);

            if ($products->isEmpty()) {
                throw ValidationException::withMessages(['category_id' => 'There are no active products to count in this scope.']);
            }

            $count->items()->createMany($products->map(fn (Product $product) => [
                'product_id' => $product->id,
                'system_qty' => $product->qty_on_hand,
                'unit_cost' => $product->avg_cost,
                'counted_qty' => null,
            ])->all());

            return $count;
        });
    }

    public function recordCount(StockCountItem $item, float|string|null $countedQty): void
    {
        if (! $item->stockCount->isDraft()) {
            throw ValidationException::withMessages(['counted_qty' => 'This count is already posted.']);
        }

        if ($countedQty !== null && $countedQty !== '' && (float) $countedQty < 0) {
            throw ValidationException::withMessages(['counted_qty' => 'Counted quantity cannot be negative.']);
        }

        $item->update(['counted_qty' => $countedQty === '' ? null : $countedQty]);
    }

    public function post(StockCount $count, User $user): StockCount
    {
        return DB::transaction(function () use ($count, $user) {
            /** @var StockCount $count */
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->getKey());

            if (! $count->isDraft()) {
                throw ValidationException::withMessages(['status' => "{$count->count_no} is already {$count->status->getLabel()}."]);
            }

            $items = $count->items()->whereNotNull('counted_qty')->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Enter at least one counted quantity before posting.']);
            }

            $products = $this->stock->lockProducts($items->pluck('product_id'));
            $variances = 0;

            foreach ($items as $item) {
                $product = $products[$item->product_id];

                // Refresh the snapshot to the live qty so the variance is exact at posting time.
                $item->update(['system_qty' => $product->qty_on_hand, 'unit_cost' => $product->avg_cost]);
                $variance = $item->variance();

                if ($variance != 0.0) {
                    $this->stock->move(
                        $product,
                        MovementType::CountVariance,
                        $variance,
                        (float) $product->avg_cost,
                        $count,
                        "Physical count {$count->count_no}",
                        $user,
                    );
                    $variances++;
                }
            }

            $count->update(['status' => DocumentStatus::Posted, 'posted_at' => now(), 'posted_by' => $user->id]);

            activity('StockCount')->causedBy($user)->performedOn($count)->event('posted')
                ->withProperties([
                    'counted_lines' => $items->count(),
                    'variance_lines' => $variances,
                    'variance_value' => round($items->sum(fn (StockCountItem $item) => $item->varianceValue() ?? 0), 2),
                ])
                ->log("Physical count {$count->count_no} posted");

            return $count;
        });
    }

    public function cancel(StockCount $count, User $user): void
    {
        if (! $count->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only open counts can be cancelled.']);
        }

        $count->update(['status' => DocumentStatus::Cancelled]);

        activity('StockCount')->causedBy($user)->performedOn($count)->event('cancelled')->log("Physical count {$count->count_no} cancelled");
    }
}
