<?php

namespace App\Http\Resources;

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use Illuminate\Http\Request;

/** A waste / damage entry. Load `items.unit`, `admin`. */
class StockAdjustmentResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var StockAdjustment $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->uuid,
            'code' => $entry->code(),
            'type' => ['value' => $entry->type->value, 'label' => $entry->type->label()],
            'reason' => $entry->reason,
            'business_date' => $entry->business_date->toDateString(),
            'total_cost' => $entry->total_cost,
            'created_at' => static::iso($entry->created_at),
            'admin' => $entry->relationLoaded('admin') ? $entry->admin?->name : null,
            'items' => $entry->relationLoaded('items') ? $entry->items->map(fn (StockAdjustmentItem $i) => [
                'name' => $i->item_name,
                'quantity' => $i->quantity,
                'unit' => $i->unit?->short_name,
                'line_cost' => $i->line_cost,
            ])->all() : [],
        ];
    }
}
