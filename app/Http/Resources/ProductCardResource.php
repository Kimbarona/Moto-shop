<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Product card for the POS / stock lookup. Never includes cost — the front of house
 * does not need it, so it is not sent to anyone from here.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $onHand = (float) $this->qty_on_hand;
        $reserved = (float) ($this->reserved_qty ?? $this->reservedQty());
        $available = round($onHand - $reserved, 2);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'part_number' => $this->part_number,
            'brand' => $this->brand?->name,
            'category_id' => $this->category_id,
            'category' => $this->category?->name,
            'unit' => $this->unit?->abbreviation,
            'allows_decimal' => (bool) $this->unit?->allows_decimal,
            'srp' => (float) $this->srp,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $available,
            'reorder_level' => (float) $this->reorder_level,
            'low_stock' => $available <= (float) $this->reorder_level,
            'bin_location' => $this->bin_location,
            'is_universal' => (bool) $this->is_universal,
            'image' => $this->image ? Storage::disk('public')->url($this->image) : null,
            'fits' => $this->whenLoaded('fitsModels', fn () => $this->fitsModels->map(fn ($model) => [
                'id' => $model->id,
                'name' => $model->full_name,
                'notes' => $model->pivot?->notes,
            ])->values()),
        ];
    }
}
