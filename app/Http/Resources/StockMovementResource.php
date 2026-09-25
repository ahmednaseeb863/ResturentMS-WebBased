<?php

namespace App\Http\Resources;

use App\Models\RawMaterial;
use Illuminate\Http\Request;

/** A stock ledger line. Load `stockable.stockUnit` and `admin`. */
class StockMovementResource extends Resource
{
    public function toArray(Request $request): array
    {
        $item = $this->stockable;

        return [
            'id' => $this->uuid,
            'created_at' => static::iso($this->created_at),
            'business_date' => $this->business_date?->toDateString(),
            'type' => ['value' => $this->type->value, 'label' => $this->type->label(), 'tone' => $this->type->tone()],
            'item' => $item ? [
                'id' => $item->uuid,
                'kind' => $item instanceof RawMaterial ? 'raw_material' : 'ready_item',
                'name' => $item->name,
                'unit' => $item->stockUnit?->short_name,
            ] : null,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'balance_after' => $this->balance_after,
            'note' => $this->note,
            'admin' => $this->admin?->name,
        ];
    }
}
