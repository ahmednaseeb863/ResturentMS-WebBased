<?php

namespace App\Support;

use App\Enums\KitchenStatus;
use App\Http\Resources\Resource;
use App\Models\DiningTable;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Shift;

/**
 * What the waiter app's table grid shows (PLAN §4.11, current branch, uuids only): every
 * active table by area with its status and the open order on it — items, how many are
 * ready to take out / still cooking, the bill, whether the bill was asked for, and
 * whether it is the signed-in waiter's order.
 */
class WaiterFloor
{
    public static function tables(?Employee $me): array
    {
        $open = Order::query()->open()->whereNotNull('table_id')->with('waiter')
            ->withSum(['lines as live_quantity' => fn ($q) => $q->live()], 'quantity')
            ->withCount([
                'items as ready_count' => fn ($q) => $q->live()->where('kitchen_status', KitchenStatus::Ready),
                'items as cooking_count' => fn ($q) => $q->live()->whereIn('kitchen_status', [KitchenStatus::Pending, KitchenStatus::Preparing]),
            ])
            ->get()->keyBy('table_id');

        return DiningTable::query()->active()->with('area')->get()
            ->sortBy([fn ($a, $b) => [$a->area?->sort_order, $a->area?->name] <=> [$b->area?->sort_order, $b->area?->name], fn ($a, $b) => strnatcasecmp($a->name, $b->name)])
            ->map(fn (DiningTable $t) => [
                'id' => $t->uuid,
                'name' => $t->name,
                'area' => $t->area?->name,
                'capacity' => $t->capacity,
                'status' => ['value' => $t->status->value, 'label' => $t->status->label()],
                'order' => ($o = $open->get($t->id)) ? static::order($o, $me) : null,
            ])->values()->all();
    }

    /** Can orders be taken? Waiter orders need any open shift in the branch (PLAN §6). */
    public static function shiftOpen(): bool
    {
        return Shift::query()->open()->exists();
    }

    private static function order(Order $order, ?Employee $me): array
    {
        return [
            'id' => $order->uuid,
            'code' => $order->code(),
            'is_draft' => $order->isDraft(),
            'status' => ['value' => $order->status->value, 'label' => $order->status->label(), 'tone' => $order->status->tone()],
            'waiter' => $order->waiter?->name,
            'mine' => $me !== null && $order->waiter_id === $me->id,
            'guests' => $order->guests,
            'items' => $order->isDraft() ? collect($order->held_items ?? [])->sum('quantity') : (int) $order->live_quantity,
            'ready' => (int) $order->ready_count,
            'cooking' => (int) $order->cooking_count,
            'total' => $order->grand_total,
            'due' => $order->isDraft() ? (float) $order->grand_total : $order->due(),
            'bill_requested' => $order->billRequested(),
            'placed_at' => Resource::iso($order->placed_at ?? $order->created_at),
        ];
    }
}
