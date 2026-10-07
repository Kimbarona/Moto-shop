<?php

namespace App\Services\Stock;

use App\Enums\DocumentStatus;
use App\Models\StockReceiving;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock In: draft → posted. Posting adds stock and updates weighted average costs.
 */
class ReceivingService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  array{date: string, supplier_id?: int|null, reference_no?: string|null, notes?: string|null}  $header
     * @param  list<array{product_id: int, qty: float|string, unit_cost: float|string}>  $lines
     */
    public function createDraft(array $header, array $lines, User $user): StockReceiving
    {
        $this->validateLines($lines);

        return DB::transaction(function () use ($header, $lines, $user) {
            $receiving = StockReceiving::create([...$header, 'status' => DocumentStatus::Draft, 'created_by' => $user->id]);
            $receiving->items()->createMany($lines);

            return $receiving;
        });
    }

    public function post(StockReceiving $receiving, User $user): StockReceiving
    {
        return DB::transaction(function () use ($receiving, $user) {
            /** @var StockReceiving $receiving */
            $receiving = StockReceiving::query()->lockForUpdate()->findOrFail($receiving->getKey());

            if (! $receiving->isDraft()) {
                throw ValidationException::withMessages(['status' => "{$receiving->receiving_no} is already {$receiving->status->getLabel()}."]);
            }

            $items = $receiving->items()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Add at least one line before posting.']);
            }

            $this->validateLines($items->map->only(['product_id', 'qty', 'unit_cost'])->all());
            $this->stock->lockProducts($items->pluck('product_id'));

            $remarks = trim('Stock in '.$receiving->receiving_no.($receiving->reference_no ? ' · Ref '.$receiving->reference_no : ''));

            foreach ($items as $item) {
                $this->stock->stockIn($item->product_id, (float) $item->qty, (float) $item->unit_cost, $receiving, $remarks, $user);
            }

            $receiving->update(['status' => DocumentStatus::Posted, 'posted_at' => now(), 'posted_by' => $user->id]);

            activity('StockReceiving')->causedBy($user)->performedOn($receiving)->event('posted')
                ->withProperties(['lines' => $items->count(), 'total' => $receiving->total()])
                ->log("Stock in {$receiving->receiving_no} posted");

            return $receiving;
        });
    }

    public function cancel(StockReceiving $receiving, User $user): void
    {
        if (! $receiving->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only drafts can be cancelled. Use an adjustment to correct posted stock.']);
        }

        $receiving->update(['status' => DocumentStatus::Cancelled]);

        activity('StockReceiving')->causedBy($user)->performedOn($receiving)->event('cancelled')->log("Stock in {$receiving->receiving_no} cancelled");
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validateLines(array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            if ((float) ($line['qty'] ?? 0) <= 0) {
                throw ValidationException::withMessages(["items.{$i}.qty" => 'Quantity must be more than zero.']);
            }

            if ((float) ($line['unit_cost'] ?? -1) < 0) {
                throw ValidationException::withMessages(["items.{$i}.unit_cost" => 'Unit cost cannot be negative.']);
            }
        }
    }
}
