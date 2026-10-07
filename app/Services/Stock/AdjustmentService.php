<?php

namespace App\Services\Stock;

use App\Enums\AdjustmentReason;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock adjustments (Damaged, Lost, Found, Internal Use, Correction, Other).
 * Remarks are required; every posting is audit-logged.
 */
class AdjustmentService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Damaged/Lost/Internal Use can only remove stock, Found can only add it,
     * Correction/Other can go either way.
     */
    public static function allowedSign(AdjustmentReason $reason): ?int
    {
        return match ($reason) {
            AdjustmentReason::Damaged, AdjustmentReason::Lost, AdjustmentReason::InternalUse => -1,
            AdjustmentReason::Found => 1,
            default => null,
        };
    }

    /**
     * @param  array{date: string, reason: AdjustmentReason|string, remarks: string}  $header
     * @param  list<array{product_id: int, qty: float|string}>  $lines  signed qty
     */
    public function createDraft(array $header, array $lines, User $user): StockAdjustment
    {
        $reason = $header['reason'] instanceof AdjustmentReason ? $header['reason'] : AdjustmentReason::from($header['reason']);
        $this->validate($reason, (string) ($header['remarks'] ?? ''), $lines);

        return DB::transaction(function () use ($header, $lines, $user) {
            $adjustment = StockAdjustment::create([...$header, 'status' => DocumentStatus::Draft, 'created_by' => $user->id]);
            $adjustment->items()->createMany(array_map(fn (array $line) => [
                'product_id' => $line['product_id'],
                'qty' => $line['qty'],
            ], $lines));

            return $adjustment;
        });
    }

    public function post(StockAdjustment $adjustment, User $user): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user) {
            /** @var StockAdjustment $adjustment */
            $adjustment = StockAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());

            if (! $adjustment->isDraft()) {
                throw ValidationException::withMessages(['status' => "{$adjustment->adjustment_no} is already {$adjustment->status->getLabel()}."]);
            }

            $items = $adjustment->items()->get();
            $this->validate($adjustment->reason, (string) $adjustment->remarks, $items->map->only(['product_id', 'qty'])->all());

            $products = $this->stock->lockProducts($items->pluck('product_id'));
            $remarks = "{$adjustment->reason->getLabel()} ({$adjustment->adjustment_no}): {$adjustment->remarks}";

            foreach ($items as $item) {
                $qty = (float) $item->qty;
                $cost = (float) $products[$item->product_id]->avg_cost;

                $this->stock->move(
                    $item->product_id,
                    $qty > 0 ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                    $qty,
                    $cost,
                    $adjustment,
                    $remarks,
                    $user,
                );

                $item->update(['unit_cost' => $cost]);
            }

            $adjustment->update(['status' => DocumentStatus::Posted, 'posted_at' => now(), 'posted_by' => $user->id]);

            activity('StockAdjustment')->causedBy($user)->performedOn($adjustment)->event('posted')
                ->withProperties([
                    'reason' => $adjustment->reason->value,
                    'remarks' => $adjustment->remarks,
                    'lines' => $items->map(fn (StockAdjustmentItem $item) => [
                        'product_id' => $item->product_id,
                        'qty' => (float) $item->qty,
                        'unit_cost' => (float) $item->unit_cost,
                    ])->all(),
                ])
                ->log("Stock adjustment {$adjustment->adjustment_no} posted");

            return $adjustment;
        });
    }

    public function cancel(StockAdjustment $adjustment, User $user): void
    {
        if (! $adjustment->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only drafts can be cancelled.']);
        }

        $adjustment->update(['status' => DocumentStatus::Cancelled]);

        activity('StockAdjustment')->causedBy($user)->performedOn($adjustment)->event('cancelled')->log("Stock adjustment {$adjustment->adjustment_no} cancelled");
    }

    /**
     * Lines that would push on-hand below what Pending orders have reserved.
     *
     * @return list<string>
     */
    public function reservationWarnings(StockAdjustment $adjustment): array
    {
        $warnings = [];

        foreach ($adjustment->items()->with('product')->get() as $item) {
            if ((float) $item->qty >= 0) {
                continue;
            }

            /** @var Product $product */
            $product = $item->product;
            $after = (float) $product->qty_on_hand + (float) $item->qty;
            $reserved = $product->reservedQty();

            if ($after < $reserved) {
                $warnings[] = sprintf('%s: on hand after this adjustment (%s) is below the %s reserved for Pending orders.', $product->name, $after, $reserved);
            }
        }

        return $warnings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validate(AdjustmentReason $reason, string $remarks, array $lines): void
    {
        if (trim($remarks) === '') {
            throw ValidationException::withMessages(['remarks' => 'Remarks are required for stock adjustments.']);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }

        $sign = self::allowedSign($reason);

        foreach (array_values($lines) as $i => $line) {
            $qty = (float) ($line['qty'] ?? 0);

            if ($qty == 0.0) {
                throw ValidationException::withMessages(["items.{$i}.qty" => 'Quantity cannot be zero.']);
            }

            if ($sign !== null && $qty * $sign < 0) {
                throw ValidationException::withMessages([
                    "items.{$i}.qty" => $sign < 0
                        ? "{$reason->getLabel()} can only remove stock (use a negative qty)."
                        : "{$reason->getLabel()} can only add stock (use a positive qty).",
                ]);
            }
        }
    }
}
