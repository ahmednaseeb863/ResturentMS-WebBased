<?php

namespace App\Support;

use App\Actions\CompleteOrder;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\KitchenTicket;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Keeps kitchen tickets and orders in step with their lines (PLAN §5 / §4.12):
 *   ticket — pending → preparing (started) → ready (every live line ready) → served
 *   order  — placed → preparing → ready → served (dine-in); takeaway / delivery stay ready
 * Voided lines don't count. Screens pick the change up on their next poll (LiveUpdates).
 */
class KitchenSync
{
    public static function ticket(KitchenTicket $ticket): void
    {
        $status = static::statusOf($ticket->liveItems()->get()->pluck('kitchen_status'));
        if (! $status) {
            return; // everything voided — the ticket leaves the board
        }

        $now = now();
        $ticket->status = $status;
        $ticket->started_at = $status === KitchenStatus::Pending ? $ticket->started_at : ($ticket->started_at ?? $now);
        $ticket->completed_at = in_array($status, [KitchenStatus::Ready, KitchenStatus::Served], true) ? ($ticket->completed_at ?? $now) : null;
        $ticket->served_at = $status === KitchenStatus::Served ? ($ticket->served_at ?? $now) : null;
        $ticket->save();
    }

    /** Move the order along with its kitchen lines; tells the POS when it becomes ready. */
    public static function order(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Placed, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Served], true)) {
            return;
        }

        $kitchen = static::statusOf($order->items()->live()->whereNotNull('kitchen_status')->get()->pluck('kitchen_status'));
        if (! $kitchen) {
            return;
        }

        $target = match ($kitchen) {
            KitchenStatus::Pending => OrderStatus::Placed,
            KitchenStatus::Preparing => OrderStatus::Preparing,
            KitchenStatus::Ready => OrderStatus::Ready,
            KitchenStatus::Served => $order->type === OrderType::DineIn ? OrderStatus::Served : OrderStatus::Ready,
        };

        if ($target === $order->status) {
            return;
        }

        $order->moveTo($target);

        if ($target === OrderStatus::Ready) {
            $order->loadMissing('table', 'customer');
            LiveUpdates::bump('orders', $order->branch_id, ['id' => $order->uuid, 'code' => $order->code(), 'label' => $order->label()]);
        }

        // paid up front: completes now that the kitchen is done
        if (in_array($target, [OrderStatus::Ready, OrderStatus::Served], true)) {
            CompleteOrder::ifSettled($order);
        }
    }

    /** Kitchen screens of the branch reload on their next poll. */
    public static function changed(int $branchId): void
    {
        LiveUpdates::bump('kitchen', $branchId);
    }

    /** @param  Collection<int, KitchenStatus>  $statuses */
    public static function statusOf(Collection $statuses): ?KitchenStatus
    {
        $statuses = $statuses->filter();

        return match (true) {
            $statuses->isEmpty() => null,
            $statuses->every(fn ($s) => $s === KitchenStatus::Served) => KitchenStatus::Served,
            $statuses->every(fn ($s) => in_array($s, [KitchenStatus::Ready, KitchenStatus::Served], true)) => KitchenStatus::Ready,
            $statuses->contains(fn ($s) => $s !== KitchenStatus::Pending) => KitchenStatus::Preparing,
            default => KitchenStatus::Pending,
        };
    }
}
