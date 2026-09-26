<?php

namespace App\Actions;

use App\Enums\KitchenStatus;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Activity;
use App\Support\KitchenSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The waiter took ready items to the table (PLAN §4.11): ready lines → served. With no
 * uuids every ready line of the order is served. Tickets and the order follow the lines
 * (KitchenSync); a ticket leaves the kitchen board once all its lines are served.
 */
class ServeOrderItems
{
    /** @param  list<string>  $uuids  order line uuids; empty = all ready lines */
    public function handle(Order $order, array $uuids = []): int
    {
        return DB::transaction(function () use ($order, $uuids) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['order' => "Order {$order->code()} is {$order->status->label()}."]);
            }

            $items = $order->items()->live()->where('kitchen_status', KitchenStatus::Ready)
                ->when($uuids, fn ($q) => $q->whereIn('uuid', $uuids))
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'Nothing is ready to serve.']);
            }

            OrderItem::query()->whereKey($items->modelKeys())
                ->update(['kitchen_status' => KitchenStatus::Served, 'served_at' => now(), 'updated_at' => now()]);

            KitchenTicket::query()->whereKey($items->pluck('kitchen_ticket_id')->filter()->unique()->all())->get()
                ->each(fn (KitchenTicket $ticket) => KitchenSync::ticket($ticket->setRelation('order', $order)));
            KitchenSync::order($order);
            KitchenSync::changed($order->branch_id);

            Activity::log('items_served', $order, ['items' => $items->map(fn (OrderItem $i) => "{$i->quantity} × {$i->fullName()}")->all()]);

            return $items->count();
        });
    }
}
