<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Models\Admin;
use App\Models\Expense;
use App\Models\Shift;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids a wrongly entered expense (never deleted). A cash expense puts the money back into
 * a drawer as cash in: its own shift while that is still open, else the voider's open shift.
 */
class VoidExpense
{
    public function __construct(private RecordCashMovement $cash) {}

    public function handle(Expense $expense, string $reason, Admin $admin): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $admin) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->isVoided()) {
                throw ValidationException::withMessages(['reason' => "{$expense->code()} is already voided."]);
            }

            $movement = null;
            if ($expense->paid_from === PaymentMethod::Cash) {
                $shift = $expense->shift?->isOpen() ? $expense->shift : Shift::openFor($admin);
                if (! $shift) {
                    throw ValidationException::withMessages(['reason' => "{$expense->shift?->code()} is closed — open your shift, the money goes back into your drawer."]);
                }
                $movement = $this->cash->handle($shift, CashMovementType::CashIn, (float) $expense->amount, "{$expense->code()} voided — {$reason}", $expense);
            }

            $expense->update([
                'voided_at' => now(),
                'voided_by' => $admin->id,
                'void_reason' => $reason,
                'void_movement_id' => $movement?->id,
            ]);

            Activity::log('expense_voided', $expense, array_filter([
                'amount' => money((float) $expense->amount, $expense->branch_id),
                'reason' => $reason,
                'back to' => $movement ? Shift::query()->withoutGlobalScope('branch')->find($movement->shift_id)?->code() : null,
            ]));

            return $expense;
        });
    }
}
