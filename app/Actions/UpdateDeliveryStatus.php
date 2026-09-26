<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Admin;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Activity;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a delivery along (PLAN §4.14 / §5), by the rider (rider panel) or at the counter:
 *   out     — assigned → out for delivery (picked up; the kitchen must be done). The order
 *             is out for delivery; the cash to collect is what is still due.
 *   deliver — out / failed → delivered. Cash still due is collected by the rider: a cash
 *             payment with no shift, held by the rider until SettleRiderCash. The order
 *             is delivered, and completes when nothing is left to pay.
 *   fail    — out → failed, with the reason (the rider still has the food).
 *   return  — out / failed → returned: the food is back, the order is ready again and can
 *             go with a rider again (AssignRider) or be cancelled.
 */
class UpdateDeliveryStatus
{
    public const ACTIONS = ['out', 'deliver', 'fail', 'return'];

    public function handle(Delivery $delivery, string $action, Admin $admin, ?string $reason = null): Delivery
    {
        return DB::transaction(function () use ($delivery, $action, $admin, $reason) {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $order = Order::query()->lockForUpdate()->findOrFail($delivery->order_id);
            $delivery->setRelation('order', $order);

            $fail = fn (string $message) => throw ValidationException::withMessages(['delivery' => $message]);
            $from = $delivery->status;
            $allowed = match ($action) {
                'out' => [DeliveryStatus::Assigned],
                'deliver' => [DeliveryStatus::OutForDelivery, DeliveryStatus::Failed],
                'fail' => [DeliveryStatus::OutForDelivery],
                'return' => [DeliveryStatus::OutForDelivery, DeliveryStatus::Failed],
            };
            if (! in_array($from, $allowed, true)) {
                $fail("{$order->code()} is {$from->label()}.");
            }
            if (! $order->isOpen()) {
                $fail("Order {$order->code()} is {$order->status->label()}.");
            }

            $now = now();
            $extra = [];

            switch ($action) {
                case 'out':
                    if (! $delivery->rider_id) {
                        $fail('Give the delivery to a rider first.');
                    }
                    if (CompleteOrder::cooking($order)) {
                        $fail("{$order->code()} is still being prepared.");
                    }
                    $delivery->forceFill([
                        'status' => DeliveryStatus::OutForDelivery,
                        'picked_up_at' => $now,
                        'cash_to_collect' => $order->due(),
                    ])->save();
                    $order->moveTo(OrderStatus::OutForDelivery);
                    $extra['collect'] = $order->due() > 0 ? money($order->due(), $order->branch_id) : null;
                    break;

                case 'deliver':
                    $collected = $this->collect($order, $delivery, $admin);
                    $delivery->forceFill([
                        'status' => DeliveryStatus::Delivered,
                        'delivered_at' => $now,
                        'cash_collected' => $collected,
                    ])->save();
                    $order->moveTo(OrderStatus::Delivered);
                    CompleteOrder::ifSettled($order);
                    $extra['cash_collected'] = $collected > 0 ? money($collected, $order->branch_id) : null;
                    break;

                case 'fail':
                    if (blank($reason)) {
                        throw ValidationException::withMessages(['reason' => 'Say why it could not be delivered.']);
                    }
                    $delivery->forceFill(['status' => DeliveryStatus::Failed, 'failed_at' => $now, 'failed_reason' => $reason])->save();
                    $extra['reason'] = $reason;
                    break;

                case 'return':
                    $delivery->forceFill(['status' => DeliveryStatus::Returned, 'returned_at' => $now])->save();
                    $order->moveTo(OrderStatus::Ready, $reason);
                    $extra['reason'] = $reason;
                    break;
            }

            $delivery->loadMissing('rider');
            Activity::log('delivery_'.$delivery->status->value, $order, array_filter([
                'rider' => $delivery->rider?->name,
                ...$extra,
            ]));

            return $delivery;
        });
    }

    /** Cash the rider takes from the customer: whatever is still due. */
    private function collect(Order $order, Delivery $delivery, Admin $admin): float
    {
        $due = $order->due();
        if ($due <= 0) {
            return 0.0;
        }

        Payment::create([
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'shift_id' => null, // held by the rider until settled at a counter
            'business_date' => BusinessDate::for($order->branch_id),
            'method' => PaymentMethod::Cash,
            'amount' => $due,
            'tendered' => $due,
            'change_given' => 0,
            'received_by' => $admin->id,
            'collected_by_rider_id' => $delivery->rider_id,
        ]);

        $order->paid_total = round((float) $order->paid_total + $due, 2);
        $order->paid_at = now();
        $order->syncPaymentStatus();
        $order->save();

        return $due;
    }
}
