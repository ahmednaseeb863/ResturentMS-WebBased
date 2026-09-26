<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * An order. List: load `table`, `customer`, `waiter`, `createdBy`. Detail / POS: also
 * `orderDiscount`, `delivery`, `cancelledBy` and `lines` (with `modifiers`, `children`,
 * `discount`, `station`, `ticket`, `voidedBy`). A held order carries its cart in `held_items`.
 */
class OrderResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $discount = $order->relationLoaded('orderDiscount') ? $order->orderDiscount : null;
        $delivery = $order->relationLoaded('delivery') ? $order->delivery : null;

        return [
            'id' => $order->uuid,
            'code' => $order->code(),
            'number' => $order->order_number,
            'label' => $order->label(),
            'type' => ['value' => $order->type->value, 'label' => $order->type->label()],
            'source' => $order->source->label(),
            'status' => ['value' => $order->status->value, 'label' => $order->status->label(), 'tone' => $order->status->tone()],
            'is_open' => $order->isOpen(),
            'is_draft' => $order->isDraft(),
            'payment_status' => ['value' => $order->payment_status->value, 'label' => $order->payment_status->label()],
            'business_date' => $order->business_date->toDateString(),
            'table' => $this->ref('table'),
            'waiter' => $this->ref('waiter'),
            'customer' => $this->ref('customer', ['name', 'phone']),
            'guests' => $order->guests,
            'notes' => $order->notes,
            'created_by' => $this->ref('createdBy'),
            'created_at' => static::iso($order->created_at),
            'placed_at' => static::iso($order->placed_at),
            'cancelled_at' => static::iso($order->cancelled_at),
            'cancelled_by' => $this->ref('cancelledBy'),
            'cancel_reason' => $order->cancel_reason,
            'items_total' => $order->items_total,
            'discount_total' => $order->discount_total,
            'net_total' => $order->net_total,
            'service_charge_rate' => $order->service_charge_rate,
            'service_charge_removed' => $order->service_charge_removed,
            'service_charge' => $order->service_charge,
            'delivery_fee' => $order->delivery_fee,
            'tax_name' => $order->tax_name,
            'tax_rate' => $order->tax_rate,
            'tax_total' => $order->tax_total,
            'round_off' => $order->round_off,
            'grand_total' => $order->grand_total,
            'paid_total' => $order->paid_total,
            'discount' => $discount ? [
                'preset' => $discount->discount?->uuid,
                'name' => $discount->name,
                'type' => $discount->type->value,
                'value' => $discount->value,
                'value_text' => $discount->valueText(),
                'amount' => $discount->amount,
                'reason' => $discount->reason,
            ] : null,
            'delivery' => $delivery ? [
                'address' => $delivery->address,
                'saved_address' => $delivery->savedAddress?->uuid,
                'phone' => $delivery->phone,
                'status' => $delivery->status->label(),
            ] : null,
            'held_items' => $order->isDraft() ? array_values($order->held_items ?? []) : [],
            'item_count' => $order->isDraft()
                ? collect($order->held_items ?? [])->sum('quantity')
                : (int) ($order->live_quantity ?? 0), // withSum(... as live_quantity)
            $this->mergeWhen($order->relationLoaded('lines'), fn () => [
                'lines' => OrderItemResource::collection($order->lines)->resolve(),
            ]),
        ];
    }
}
