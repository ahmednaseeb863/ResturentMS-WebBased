<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Models\Admin;
use App\Models\Delivery;
use App\Models\Employee;
use App\Support\Activity;
use App\Support\LiveUpdates;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a delivery to a rider (PLAN §4.14) — from the deliveries board or the POS. A rider
 * can be changed until the food leaves; no rider puts it back to unassigned. A returned
 * delivery assigned again is a new attempt. The rider's phone gets an alert (polled).
 */
class AssignRider
{
    public function handle(Delivery $delivery, ?Employee $rider, Admin $admin): Delivery
    {
        return DB::transaction(function () use ($delivery, $rider, $admin) {
            $delivery = Delivery::query()->with('order')->lockForUpdate()->findOrFail($delivery->id);
            $order = $delivery->order;
            $fail = fn (string $message) => throw ValidationException::withMessages(['rider' => $message]);

            if ($delivery->rider_id === $rider?->id && $delivery->status !== DeliveryStatus::Returned) {
                return $delivery;
            }
            if ($order->isDraft() || ! $order->isOpen()) {
                $fail($order->isDraft() ? 'Send the order before giving it to a rider.' : "Order {$order->code()} is {$order->status->label()}.");
            }
            if (! $delivery->status->canAssign()) {
                $fail("{$order->code()} is {$delivery->status->label()} — the rider can't be changed now.");
            }
            if ($rider && ! $rider->isRider()) {
                $fail("{$rider->name} is not an active rider.");
            }

            $from = $delivery->rider;
            $delivery->forceFill([
                'rider_id' => $rider?->id,
                'status' => $rider ? DeliveryStatus::Assigned : DeliveryStatus::Pending,
                'assigned_at' => $rider ? now() : null,
                'assigned_by' => $rider ? $admin->id : null,
                // a new attempt after a return
                'picked_up_at' => null,
                'failed_at' => null,
                'failed_reason' => null,
                'returned_at' => null,
            ])->save();

            Activity::log($rider ? 'rider_assigned' : 'rider_unassigned', $order, array_filter([
                'rider' => $rider?->name,
                'from' => $from?->name,
            ]));

            if ($rider) {
                LiveUpdates::bump('deliveries', $delivery->branch_id, [
                    'kind' => 'assigned',
                    'id' => $delivery->uuid,
                    'rider' => $rider->uuid,
                    'code' => $order->code(),
                    'address' => $delivery->address,
                ]);
            }

            return $delivery;
        });
    }
}
