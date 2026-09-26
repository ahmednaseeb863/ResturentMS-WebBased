<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\BillSplit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Support\Activity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes payment for an order (PLAN §4.13) into the cashier's open shift: one or more
 * tenders (split payment — e.g. part cash, part bank transfer), for the whole bill or one
 * part of a split bill. Cash may be more than its share: the rest is given back as change.
 * Paid in full → the order completes (CompleteOrder) unless the kitchen is still cooking.
 */
class TakePayment
{
    /**
     * @param  list<array{method: PaymentMethod, amount: float, tendered: ?float, bank: ?BankAccount, reference: ?string, proof: ?UploadedFile}>  $tenders
     * @return array{payments: list<Payment>, change: float, paid: float, completed: bool, settled: bool}
     */
    public function handle(Order $order, array $tenders, ?BillSplit $split, Admin $admin, Shift $shift): array
    {
        // store transfer screenshots first (files are kept even if the payment fails)
        foreach ($tenders as $i => $tender) {
            $tenders[$i]['proof_path'] = $tender['proof'] ? $tender['proof']->store('payments', 'public') : null;
        }

        return DB::transaction(function () use ($order, $tenders, $split, $admin, $shift) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $fail = fn (string $message) => throw ValidationException::withMessages(['payment' => $message]);

            if (! $order->isOpen() || $order->isDraft()) {
                $fail($order->isDraft() ? 'Send the order before taking payment.' : "Order {$order->code()} is {$order->status->label()}.");
            }
            if (! $shift->isOpen()) {
                $fail("Shift {$shift->code()} is closed — open your shift to take payments.");
            }

            $due = $order->due();
            if ($split) {
                if ($split->order_id !== $order->id || $split->isTrashed()) {
                    $fail('That part of the bill no longer exists — split the bill again.');
                }
                $due = min($due, $split->due());
            }

            $total = round(array_sum(array_column($tenders, 'amount')), 2);
            match (true) {
                $due <= 0 && $total > 0 => $fail($split ? "{$split->label} is already paid." : 'The order is already paid.'),
                $due > 0 && $total <= 0 => $fail('Enter the amount received.'),
                $total > $due + 0.001 => $fail('That is more than the '.money($due, $order->branch_id).' due.'),
                default => null,
            };

            $payments = [];
            $change = 0.0;
            foreach ($tenders as $tender) {
                /** @var PaymentMethod $method */
                $method = $tender['method'];
                $amount = round($tender['amount'], 2);
                if ($amount <= 0) {
                    continue;
                }
                if (! $method->enabled($order->branch_id)) {
                    $fail("{$method->label()} payments are switched off for this branch.");
                }

                $tendered = null;
                $changeGiven = 0.0;
                if ($method === PaymentMethod::Cash) {
                    $tendered = round($tender['tendered'] ?? $amount, 2);
                    if ($tendered < $amount) {
                        $fail('Cash received is less than the cash amount.');
                    }
                    $changeGiven = round($tendered - $amount, 2);
                    $change += $changeGiven;
                }

                $payments[] = Payment::create([
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'bill_split_id' => $split?->id,
                    'shift_id' => $shift->id,
                    'business_date' => $shift->business_date,
                    'method' => $method,
                    'bank_account_id' => $method === PaymentMethod::BankTransfer ? $tender['bank']?->id : null,
                    'amount' => $amount,
                    'tendered' => $tendered,
                    'change_given' => $changeGiven,
                    'reference_no' => $method === PaymentMethod::BankTransfer ? $tender['reference'] : null,
                    'proof_image' => $method === PaymentMethod::BankTransfer ? $tender['proof_path'] : null,
                    'received_by' => $admin->id,
                ]);
            }

            $order->paid_total = round((float) $order->paid_total + $total, 2);
            $order->shift_id = $shift->id; // the shift the order is paid in
            if ($order->due() <= 0) {
                $order->paid_at = now();
            }
            $order->syncPaymentStatus();
            $order->save();

            if ($payments) {
                Activity::log('payment_taken', $order, array_filter([
                    'paid' => collect($payments)->map(fn (Payment $p) => $p->methodText().' '.money($p->amount, $order->branch_id))->all(),
                    'part' => $split?->label,
                    'change' => $change > 0 ? money($change, $order->branch_id) : null,
                    'shift' => $shift->code(),
                ]));
            }

            $settled = $order->due() <= 0;
            $completed = CompleteOrder::ifSettled($order);

            return ['payments' => $payments, 'change' => round($change, 2), 'paid' => $total, 'completed' => $completed, 'settled' => $settled];
        });
    }
}
