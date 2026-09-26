<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shift;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\ShiftSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives money back on a payment (PLAN §4.13), recorded in the refunder's shift. Cash comes
 * out of that shift's drawer (never more than is in it); a transfer goes out of a bank
 * account. The order keeps less money (`paid_total`); a completed order with nothing
 * kept becomes Refunded. An open order can be paid again, voided or cancelled.
 */
class RefundPayment
{
    public function handle(
        Payment $payment,
        float $amount,
        PaymentMethod $method,
        ?BankAccount $bank,
        string $reason,
        ?string $reference,
        Admin $admin,
        ?Shift $shift,
        ?Admin $approver = null,
    ): Refund {
        return DB::transaction(function () use ($payment, $amount, $method, $bank, $reason, $reference, $admin, $shift, $approver) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $amount = round($amount, 2);
            $fail = fn (string $message) => throw ValidationException::withMessages(['amount' => $message]);

            if ($amount <= 0) {
                $fail('Enter the amount to refund.');
            }
            if ($amount > $payment->refundable()) {
                $fail('Only '.money($payment->refundable(), $order->branch_id).' of this payment can be refunded.');
            }

            if ($method === PaymentMethod::Cash) {
                if (! $shift) {
                    $fail('Open your shift — cash refunds come out of your drawer.');
                }
                $shift = Shift::query()->withoutGlobalScope('branch')->lockForUpdate()->findOrFail($shift->id);
                $inDrawer = ShiftSummary::of($shift)->expectedCash();
                if ($amount > $inDrawer) {
                    $fail(setting('shifts.blind_close', $order->branch_id)
                        ? 'That is more than the cash in your drawer.'
                        : 'Only '.money($inDrawer, $order->branch_id).' is in your drawer.');
                }
            } elseif (! $bank) {
                $fail('Pick the bank account the refund is sent from.');
            }

            $refund = Refund::create([
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'shift_id' => $shift?->id,
                'business_date' => $shift?->business_date ?? BusinessDate::for($order->branch_id),
                'method' => $method,
                'bank_account_id' => $method === PaymentMethod::BankTransfer ? $bank?->id : null,
                'amount' => $amount,
                'reason' => $reason,
                'reference_no' => $method === PaymentMethod::BankTransfer ? $reference : null,
                'refunded_by' => $admin->id,
                'approved_by' => $approver?->id,
            ]);

            $payment->refunded_total = round((float) $payment->refunded_total + $amount, 2);
            $payment->save();

            $order->paid_total = round((float) $order->paid_total - $amount, 2);
            $order->refunded_total = round((float) $order->refunded_total + $amount, 2);
            if ($order->isOpen()) {
                $order->paid_at = null;
            }
            $order->syncPaymentStatus();
            $order->save();

            if (! $order->isOpen() && (float) $order->paid_total <= 0 && $order->status !== OrderStatus::Refunded) {
                $order->moveTo(OrderStatus::Refunded, $reason);
            }

            Activity::log('refund', $order, array_filter([
                'amount' => money($amount, $order->branch_id),
                'method' => $method->label().($bank && $method === PaymentMethod::BankTransfer ? ' · '.$bank->bank_name : ''),
                'reason' => $reason,
                'approved_by' => $approver?->name,
            ]));

            return $refund;
        });
    }
}
