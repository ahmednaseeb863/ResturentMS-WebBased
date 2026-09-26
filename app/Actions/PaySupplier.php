<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\Purchase;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays a supplier (PLAN §4.16 / §6): cash out of the payer's open shift drawer (a supplier
 * payment cash movement — never more than the drawer holds) or a bank transfer from an
 * account of the branch. Against one purchase (not more than it still owes), or on
 * account — spread over the supplier's unpaid purchases, oldest first; anything left
 * over is an advance.
 */
class PaySupplier
{
    public function __construct(private RecordCashMovement $cash) {}

    public function handle(
        Supplier $supplier,
        ?Purchase $purchase,
        float $amount,
        PaymentMethod $method,
        ?Shift $shift,
        ?BankAccount $bank,
        ?string $reference,
        ?string $notes,
        Admin $admin,
    ): SupplierPayment {
        return DB::transaction(function () use ($supplier, $purchase, $amount, $method, $shift, $bank, $reference, $notes, $admin) {
            $branchId = app(CurrentBranch::class)->id();
            $amount = round($amount, 2);
            $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

            if ($amount <= 0) {
                $fail('amount', 'Enter the amount paid.');
            }
            if ($purchase) {
                $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
                if ($purchase->supplier_id !== $supplier->id) {
                    $fail('purchase', 'That purchase is not from this supplier.');
                }
                if ($amount > $purchase->due() + 0.001) {
                    $fail('amount', "{$purchase->code()} owes only ".money($purchase->due(), $branchId).'.');
                }
            }

            $movement = null;
            if ($method === PaymentMethod::Cash) {
                if (! $shift?->isOpen()) {
                    $fail('method', 'Open your shift to pay in cash — it comes out of your drawer.');
                }
                $movement = $this->cash->handle(
                    $shift, CashMovementType::SupplierPayment, $amount,
                    "{$supplier->name}".($purchase ? " — {$purchase->code()}" : ' — on account'),
                );
            } elseif (! $bank) {
                $fail('bank', 'Pick the bank account the money was sent from.');
            }

            $payment = SupplierPayment::create([
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchase?->id,
                'business_date' => $method === PaymentMethod::Cash ? $shift->business_date : BusinessDate::for($branchId),
                'method' => $method,
                'amount' => $amount,
                'shift_id' => $method === PaymentMethod::Cash ? $shift->id : null,
                'cash_movement_id' => $movement?->id,
                'bank_account_id' => $method === PaymentMethod::BankTransfer ? $bank->id : null,
                'reference_no' => $reference,
                'notes' => $notes,
                'paid_by' => $admin->id,
            ]);

            $paidOff = $this->allocate($supplier, $purchase, $amount, $branchId);

            Activity::log('supplier_paid', $supplier, array_filter([
                'amount' => money($amount, $branchId),
                'method' => $method->label().($bank ? " · {$bank->bank_name}" : ''),
                'purchases' => $paidOff ?: null,
                'shift' => $shift?->code(),
            ]));

            return $payment;
        });
    }

    /** @return list<string> codes of the purchases the money went to */
    private function allocate(Supplier $supplier, ?Purchase $purchase, float $amount, int $branchId): array
    {
        $purchases = $purchase
            ? collect([$purchase])
            : Purchase::query()->where('supplier_id', $supplier->id)->where('branch_id', $branchId)
                ->whereRaw('total - returned_total - paid_total > 0')->oldest('id')->lockForUpdate()->get();

        $codes = [];
        foreach ($purchases as $p) {
            if ($amount <= 0) {
                break;
            }
            $part = min($amount, $p->due());
            if ($part <= 0) {
                continue;
            }
            $p->paid_total = round((float) $p->paid_total + $part, 2);
            $p->syncPaymentStatus();
            $p->save();
            $amount = round($amount - $part, 2);
            $codes[] = $p->code();
        }

        return $codes;
    }
}
