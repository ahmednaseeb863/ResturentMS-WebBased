<?php

namespace App\Http\Resources;

use App\Enums\ConsumptionStatus;
use App\Enums\OrderType;
use App\Models\KitchenTicket;
use App\Models\OrderItem;
use Illuminate\Http\Request;

/** A kitchen ticket on the KDS. Load `station`, `order.table`, `order.waiter`, `order.customer`, `items.modifiers`, `items.parent`. */
class KitchenTicketResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var KitchenTicket $ticket */
        $ticket = $this->resource;
        $order = $ticket->order;

        return [
            'id' => $ticket->uuid,
            'code' => $ticket->code(),
            'status' => ['value' => $ticket->status->value, 'label' => $ticket->status->label(), 'tone' => $ticket->status->tone()],
            'station' => $this->ref('station'),
            'order' => [
                'id' => $order->uuid,
                'code' => $order->code(),
                'type' => ['value' => $order->type->value, 'label' => $order->type->label()],
                'table' => $order->type === OrderType::DineIn ? $order->table?->name : null,
                'waiter' => $order->waiter?->name,
                'guests' => $order->guests,
                'customer' => $order->type !== OrderType::DineIn ? $order->customer?->name : null,
                'notes' => $order->notes,
                'status' => $order->status->value,
            ],
            'items' => $ticket->items->map(fn (OrderItem $item) => [
                'id' => $item->uuid,
                'quantity' => $item->quantity,
                'name' => $item->fullName(),
                'extras' => $item->modifiers->pluck('name')->all(),
                'deal' => $item->parent?->item_name,
                'notes' => $item->notes,
                'status' => $item->kitchen_status?->value,
                'confirm' => $item->consumption_status === ConsumptionStatus::Pending,
                'voided' => $item->isVoided(),
                'void_reason' => $item->void_reason,
            ])->all(),
            'sent_at' => static::iso($ticket->sent_at),
            'started_at' => static::iso($ticket->started_at),
            'completed_at' => static::iso($ticket->completed_at),
            'served_at' => static::iso($ticket->served_at),
            'printed_at' => static::iso($ticket->printed_at),
        ];
    }
}
