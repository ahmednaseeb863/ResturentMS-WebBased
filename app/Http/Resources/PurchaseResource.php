<?php

namespace App\Http\Resources;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\RawMaterial;
use Illuminate\Http\Request;

/**
 * A purchase. Lists load `supplier`; the detail also `items.unit`, `items.stockable.stockUnit`,
 * `returns.items`, `returns.admin`, `payments.bankAccount`, `payments.paidBy`, `payments.shift`,
 * `receivedBy`.
 */
class PurchaseResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var Purchase $purchase */
        $purchase = $this->resource;

        return [
            'id' => $purchase->uuid,
            'code' => $purchase->code(),
            'supplier' => $this->ref('supplier'),
            'invoice_no' => $purchase->invoice_no,
            'invoice_date' => $purchase->invoice_date?->toDateString(),
            'business_date' => $purchase->business_date->toDateString(),
            'subtotal' => $purchase->subtotal,
            'discount' => $purchase->discount,
            'tax' => $purchase->tax,
            'total' => $purchase->total,
            'paid_total' => $purchase->paid_total,
            'returned_total' => $purchase->returned_total,
            'due' => $purchase->due(),
            'payment_status' => ['value' => $purchase->payment_status->value, 'label' => $purchase->payment_status->label(), 'tone' => $purchase->payment_status->tone()],
            'notes' => $purchase->notes,
            'received_by' => $this->ref('receivedBy'),
            'created_at' => static::iso($purchase->created_at),
            'items_count' => $this->whenCounted('items'),
            $this->mergeWhen($purchase->relationLoaded('items'), fn () => [
                'items' => $purchase->items->map(fn (PurchaseItem $i) => [
                    'id' => $i->uuid,
                    'name' => $i->item_name,
                    'kind' => $i->stockable instanceof RawMaterial ? 'raw_material' : 'ready_item',
                    'quantity' => $i->quantity,
                    'unit' => $i->unit?->short_name,
                    'stock_quantity' => $i->stock_quantity,
                    'stock_unit' => $i->stockable?->stockUnit?->short_name,
                    'unit_cost' => $i->unit_cost,
                    'line_total' => $i->line_total,
                    // what can still go back, in the line's unit
                    'returnable' => round($i->returnable() * (float) $i->quantity / max((float) $i->stock_quantity, 0.001), 3),
                ])->all(),
            ]),
            $this->mergeWhen($purchase->relationLoaded('returns'), fn () => [
                'returns' => $purchase->returns->map(fn (PurchaseReturn $r) => [
                    'id' => $r->uuid,
                    'code' => $r->code(),
                    'reason' => $r->reason,
                    'total' => $r->total,
                    'created_at' => static::iso($r->created_at),
                    'admin' => $r->relationLoaded('admin') ? $r->admin?->name : null,
                    'lines' => $r->items->map(fn ($l) => [
                        'name' => $purchase->items->firstWhere('id', $l->purchase_item_id)?->item_name,
                        'quantity' => $l->quantity,
                        'unit' => $purchase->items->firstWhere('id', $l->purchase_item_id)?->unit?->short_name,
                    ])->all(),
                ])->all(),
            ]),
            $this->mergeWhen($purchase->relationLoaded('payments'), fn () => [
                'payments' => SupplierPaymentResource::collection($purchase->payments)->resolve(),
            ]),
        ];
    }
}
