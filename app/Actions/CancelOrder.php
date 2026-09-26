<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an unpaid order (PLAN §5), or discards a held one. Sent lines are voided with
 * the reason (ready items back to stock unless wasted), the table is freed and the
 * delivery cancelled. The totals stay as they were, to show what was cancelled.
 */
class CancelOrder
{
    public function __construct(private VoidOrderItem $void) {}

    public function handle(Order $order, string $reason, bool $wasted, Admin $admin, ?Admin $approver = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $wasted, $admin, $approver) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['reason' => "Order {$order->code()} is already {$order->status->label()}."]);
            }
            if ($order->delivery?->status->isDispatched()) {
                throw ValidationException::withMessages(['reason' => "{$order->code()} is {$order->delivery->status->label()} — mark it returned first."]);
            }
            if ((float) $order->paid_total > 0) {
                throw ValidationException::withMessages(['reason' => 'The order has payments — refund them first.']);
            }

            $wasDraft = $order->isDraft();
            $voided = [];
            foreach ($order->lines()->live()->get() as $line) {
                $this->void->markVoided($order, $line, $reason, $wasted, $admin, $approver);
                $voided[] = "{$line->quantity} × {$line->fullName()}";
            }

            $order->fill(['cancelled_at' => now(), 'cancelled_by' => $admin->id, 'cancel_reason' => $reason]);
            $order->moveTo(OrderStatus::Cancelled, $reason);

            if ($order->table_id) {
                SaveOrder::releaseTable($order->table_id);
            }
            $order->delivery?->update(['status' => DeliveryStatus::Cancelled]);

            Activity::log($wasDraft ? 'order_discarded' : 'order_cancelled', $order, array_filter([
                'reason' => $reason,
                'voided' => $voided ?: null,
                'wasted' => $wasted && $voided ? 'yes' : null,
                'approved_by' => $approver?->name,
            ]));

            return $order;
        });
    }
}
