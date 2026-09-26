<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Order;
use App\Support\Activity;

/**
 * Completes an order once it is settled (PLAN §5): paid in full and nothing left cooking.
 * Called after a payment and when the kitchen finishes (KitchenSync) — a takeaway paid
 * up front stays open (and gets its "ready" alert) until the kitchen is done, a delivery
 * until the rider has delivered it.
 * The table is freed as available or "needs cleaning" (Orders setting).
 */
class CompleteOrder
{
    public static function ifSettled(Order $order): bool
    {
        if (! $order->isOpen() || $order->isDraft() || $order->due() > 0 || static::cooking($order) || static::onTheWay($order)) {
            return false;
        }

        $order->completed_at = now();
        $order->paid_at ??= now();
        $order->moveTo(OrderStatus::Completed);

        if ($order->table_id && ! Order::query()->open()->where('table_id', $order->table_id)->exists()) {
            $status = setting('orders.table_after_payment', $order->branch_id) === 'cleaning' ? TableStatus::Cleaning : TableStatus::Available;
            DiningTable::query()->whereKey($order->table_id)->first()?->forceFill(['status' => $status])->saveQuietly();
        }

        Activity::log('order_completed', $order, ['total' => money($order->grand_total, $order->branch_id)]);

        // kitchens working from printed tickets: raw materials at the recipe quantities
        app(AutoConfirmConsumption::class)->forOrder($order);

        return true;
    }

    /** A delivery not delivered yet. */
    public static function onTheWay(Order $order): bool
    {
        if ($order->type !== OrderType::Delivery) {
            return false;
        }
        $status = $order->delivery()->first()?->status;

        return $status !== null && ! in_array($status, [DeliveryStatus::Delivered, DeliveryStatus::Cancelled], true);
    }

    /** Kitchen lines not ready yet. */
    public static function cooking(Order $order): bool
    {
        return $order->items()->live()->whereIn('kitchen_status', [KitchenStatus::Pending, KitchenStatus::Preparing])->exists();
    }
}
