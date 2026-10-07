<?php

namespace App\Services\Stock;

use App\Enums\DefectiveStatus;
use App\Enums\MovementType;
use App\Models\DefectiveStock;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The defective bucket. Items here are NOT sellable stock:
 *  - fromReturn(): a customer returned a defective part (it already left stock when sold,
 *    so no stock movement is written — it simply never comes back to the shelf)
 *  - fromStock(): a part on the shelf turned out defective → defective_out movement (−)
 * Then each record is either returned to the supplier or written off.
 */
class DefectiveStockService
{
    public function __construct(private readonly StockService $stock) {}

    public function fromReturn(Product $product, float $qty, float $unitCost, ?int $returnItemId, ?string $notes = null): DefectiveStock
    {
        return DefectiveStock::create([
            'product_id' => $product->id,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'return_item_id' => $returnItemId,
            'status' => DefectiveStatus::Pending,
            'notes' => $notes,
        ]);
    }

    public function fromStock(Product $product, float $qty, string $notes, User $user): DefectiveStock
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity must be more than zero.']);
        }

        if (trim($notes) === '') {
            throw ValidationException::withMessages(['notes' => 'Describe the defect.']);
        }

        return DB::transaction(function () use ($product, $qty, $notes, $user) {
            $this->stock->lockProducts([$product->id]);
            $product->refresh();

            $defective = DefectiveStock::create([
                'product_id' => $product->id,
                'qty' => $qty,
                'unit_cost' => $product->avg_cost,
                'status' => DefectiveStatus::Pending,
                'notes' => $notes,
            ]);

            $this->stock->move($product, MovementType::DefectiveOut, -$qty, (float) $product->avg_cost, $defective, 'Moved to defective: '.$notes, $user);

            return $defective;
        });
    }

    public function returnToSupplier(DefectiveStock $defective, int $supplierId, ?string $reference, User $user, ?string $notes = null): DefectiveStock
    {
        return $this->resolve($defective, DefectiveStatus::ReturnedToSupplier, $user, [
            'supplier_id' => $supplierId,
            'supplier_reference' => $reference,
        ], $notes);
    }

    public function writeOff(DefectiveStock $defective, User $user, ?string $notes = null): DefectiveStock
    {
        return $this->resolve($defective, DefectiveStatus::WrittenOff, $user, [], $notes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolve(DefectiveStock $defective, DefectiveStatus $status, User $user, array $attributes, ?string $notes): DefectiveStock
    {
        return DB::transaction(function () use ($defective, $status, $user, $attributes, $notes) {
            /** @var DefectiveStock $defective */
            $defective = DefectiveStock::query()->lockForUpdate()->findOrFail($defective->getKey());

            if (! $defective->isPending()) {
                throw ValidationException::withMessages(['status' => 'This item was already '.strtolower($defective->status->getLabel()).'.']);
            }

            $defective->update([
                ...$attributes,
                'status' => $status,
                'notes' => trim(($defective->notes ? $defective->notes."\n" : '').($notes ?? '')) ?: null,
                'resolved_at' => now(),
                'resolved_by' => $user->id,
            ]);

            return $defective;
        });
    }
}
