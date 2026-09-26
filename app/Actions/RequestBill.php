<?php

namespace App\Actions;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PrintJob;
use App\Support\Activity;
use App\Support\KitchenSync;
use App\Support\Printing\PrintQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The waiter asks for the bill (PLAN §4.11): the order is marked, the POS gets a
 * "Table 5 asks for the bill" alert, and the pre-bill prints on the receipt printer of
 * an open counter (Receipt setting). Payment is taken at a cash counter. Asking again
 * prints again; sending more items clears the request (SaveOrder).
 */
class RequestBill
{
    public function handle(Order $order, Admin $admin): ?PrintJob
    {
        return DB::transaction(function () use ($order, $admin) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->isDraft() || ! $order->isOpen()) {
                throw ValidationException::withMessages(['order' => "Order {$order->code()} has no bill to ask for."]);
            }
            if ($order->due() <= 0) {
                throw ValidationException::withMessages(['order' => "Order {$order->code()} is already paid."]);
            }

            $order->forceFill(['bill_requested_at' => now(), 'bill_requested_by' => $admin->id])->save();

            $job = setting('receipt.print_on_bill_request') ? PrintQueue::billRequest($order) : null;

            Activity::log('bill_requested', $order, array_filter(['total' => money($order->grand_total), 'printed_on' => $job?->printer->name]));
            KitchenSync::announce($order, 'bill', ['by' => $admin->name, 'total' => (float) $order->due()]);

            return $job;
        });
    }
}
