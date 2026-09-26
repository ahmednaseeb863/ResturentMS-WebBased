<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;

/** A sent order line. Load `modifiers`, `children`, `discount`, `station`, `ticket`, `voidedBy`. */
class OrderItemResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var OrderItem $item */
        $item = $this->resource;

        return [
            'id' => $item->uuid,
            'type' => $item->sellable_type,
            'name' => $item->item_name,
            'variant' => $item->variant_name,
            'full_name' => $item->fullName(),
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'modifiers_total' => $item->modifiers_total,
            'discount_amount' => $item->discount_amount,
            'line_total' => $item->line_total,
            'gross' => $item->gross(),
            'notes' => $item->notes,
            'modifiers' => $item->relationLoaded('modifiers')
                ? $item->modifiers->map(fn ($m) => ['name' => $m->name, 'price' => $m->price])->all()
                : [],
            'picks' => $item->relationLoaded('children')
                ? $item->children->map(fn (OrderItem $c) => [
                    'id' => $c->uuid,
                    'name' => $c->fullName(),
                    'quantity' => $c->quantity,
                    'kitchen_status' => $c->kitchen_status?->value,
                    'voided' => $c->isVoided(),
                ])->all()
                : [],
            'discount' => $item->relationLoaded('discount') && $item->discount ? [
                'name' => $item->discount->name,
                'value' => $item->discount->valueText(),
                'reason' => $item->discount->reason,
            ] : null,
            'kitchen_status' => $item->kitchen_status ? ['value' => $item->kitchen_status->value, 'label' => $item->kitchen_status->label(), 'tone' => $item->kitchen_status->tone()] : null,
            'station' => $item->relationLoaded('station') ? $item->station?->name : null,
            'ticket' => $item->relationLoaded('ticket') ? $item->ticket?->code() : null,
            'sent_at' => static::iso($item->sent_at),
            'voided' => $item->isVoided(),
            'voided_at' => static::iso($item->voided_at),
            'voided_by' => $item->relationLoaded('voidedBy') ? $item->voidedBy?->name : null,
            'void_reason' => $item->void_reason,
            'void_wasted' => $item->void_wasted,
        ];
    }
}
