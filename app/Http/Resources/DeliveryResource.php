<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use App\Models\OrderItem;
use Illuminate\Http\Request;

/**
 * A delivery on the board / rider panel. Load with `DeliveryBoard::WITH` (order with
 * customer, lines and `cooking_count`, zone, rider, assignedBy).
 */
class DeliveryResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var Delivery $delivery */
        $delivery = $this->resource;
        $order = $delivery->relationLoaded('order') ? $delivery->order : null;

        return [
            'id' => $delivery->uuid,
            'status' => ['value' => $delivery->status->value, 'label' => $delivery->status->label(), 'tone' => $delivery->status->tone()],
            'address' => $delivery->address,
            'phone' => $delivery->phone,
            'zone' => $this->ref('zone'),
            'rider' => $this->ref('rider', ['name', 'phone']),
            'assigned_by' => $this->ref('assignedBy'),
            'fee' => $delivery->fee,
            'cash_to_collect' => $delivery->cash_to_collect,
            'cash_collected' => $delivery->cash_collected,
            'holds_cash' => $delivery->holdsCash(),
            'failed_reason' => $delivery->failed_reason,
            'assigned_at' => static::iso($delivery->assigned_at),
            'picked_up_at' => static::iso($delivery->picked_up_at),
            'delivered_at' => static::iso($delivery->delivered_at),
            'failed_at' => static::iso($delivery->failed_at),
            'returned_at' => static::iso($delivery->returned_at),
            'settled_at' => static::iso($delivery->settled_at),
            'order' => $order ? [
                'id' => $order->uuid,
                'code' => $order->code(),
                'status' => ['value' => $order->status->value, 'label' => $order->status->label(), 'tone' => $order->status->tone()],
                'customer' => $order->relationLoaded('customer') && $order->customer ? $order->customer->name : null,
                'total' => $order->grand_total,
                'due' => $order->due(),
                'paid' => $order->paid_total,
                'notes' => $order->notes,
                'cooking' => (int) ($order->cooking_count ?? 0) > 0, // DeliveryBoard::with()
                'placed_at' => static::iso($order->placed_at),
                'items' => $order->relationLoaded('lines')
                    ? $order->lines->reject->isVoided()->map(fn (OrderItem $l) => ['quantity' => $l->quantity, 'name' => $l->fullName()])->values()->all()
                    : [],
            ] : null,
        ];
    }
}
