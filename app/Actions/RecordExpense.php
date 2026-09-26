<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Shift;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an expense (PLAN §4.18): paid from the payer's open shift drawer (a paid-out
 * expense cash movement, never more than the drawer holds — takes the shift's business
 * date) or from a bank account of the branch (on the business date given, default today).
 */
class RecordExpense
{
    public function __construct(private RecordCashMovement $cash) {}

    public function handle(
        ExpenseCategory $category,
        float $amount,
        PaymentMethod $method,
        ?Shift $shift,
        ?BankAccount $bank,
        ?string $businessDate,
        string $description,
        ?string $reference,
        ?string $attachment,
        Admin $admin,
    ): Expense {
        return DB::transaction(function () use ($category, $amount, $method, $shift, $bank, $businessDate, $description, $reference, $attachment, $admin) {
            $branchId = app(CurrentBranch::class)->id();
            $amount = round($amount, 2);
            $cash = $method === PaymentMethod::Cash;

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount spent.']);
            }
            if ($cash && ! $shift?->isOpen()) {
                throw ValidationException::withMessages(['method' => 'Open your shift to pay in cash — it comes out of your drawer.']);
            }
            if (! $cash && ! $bank) {
                throw ValidationException::withMessages(['bank' => 'Pick the bank account the money was paid from.']);
            }

            $expense = Expense::create([
                'branch_id' => $branchId,
                'number' => Expense::nextNumber($branchId),
                'expense_category_id' => $category->id,
                'business_date' => $cash ? $shift->business_date : ($businessDate ?: BusinessDate::for($branchId)),
                'amount' => $amount,
                'description' => $description,
                'reference_no' => $reference,
                'paid_from' => $method,
                'shift_id' => $cash ? $shift->id : null,
                'bank_account_id' => $cash ? null : $bank->id,
                'attachment' => $attachment,
                'created_by' => $admin->id,
            ]);

            if ($cash) {
                $movement = $this->cash->handle($shift, CashMovementType::Expense, $amount, "{$expense->code()} · {$category->name} — {$description}", $expense);
                $expense->update(['cash_movement_id' => $movement->id]);
            }

            Activity::log('expense_recorded', $expense, array_filter([
                'category' => $category->name,
                'amount' => money($amount, $branchId),
                'paid from' => $cash ? "Cash · {$shift->code()}" : "Bank · {$bank->bank_name}",
                'description' => $description,
            ]));

            return $expense;
        });
    }
}
