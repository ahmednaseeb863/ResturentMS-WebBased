<?php

namespace App\Http\Resources;

use App\Models\RawMaterial;
use App\Models\StockCount;
use App\Models\StockCountItem;
use Illuminate\Http\Request;

/** A stock count. Load `createdBy`, `submittedBy`, `approvedBy`; the sheet also `items.stockable.stockUnit`. */
class StockCountResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var StockCount $count */
        $count = $this->resource;

        return [
            'id' => $count->uuid,
            'code' => $count->code(),
            'status' => ['value' => $count->status->value, 'label' => $count->status->label(), 'tone' => $count->status->tone()],
            'scope' => $count->scope,
            'notes' => $count->notes,
            'business_date' => $count->business_date->toDateString(),
            'created_at' => static::iso($count->created_at),
            'created_by' => $this->ref('createdBy'),
            'submitted_by' => $this->ref('submittedBy'),
            'submitted_at' => static::iso($count->submitted_at),
            'approved_by' => $this->ref('approvedBy'),
            'approved_at' => static::iso($count->approved_at),
            'variance_value' => $count->variance_value,
            'items_count' => $this->whenCounted('items'),
            'counted_count' => $this->when(isset($count->counted_count), fn () => (int) $count->counted_count),
            $this->mergeWhen($count->relationLoaded('items'), fn () => [
                'items' => $count->items->map(fn (StockCountItem $i) => [
                    'id' => $i->uuid,
                    'name' => $i->item_name,
                    'kind' => $i->stockable instanceof RawMaterial ? 'raw_material' : 'ready_item',
                    'code' => $i->stockable?->code,
                    'unit' => $i->stockable?->stockUnit?->short_name,
                    'system_qty' => $i->system_qty,
                    'counted_qty' => $i->counted_qty,
                    'variance' => $i->variance(),
                    'unit_cost' => $i->unit_cost,
                ])->all(),
            ]),
        ];
    }
}
