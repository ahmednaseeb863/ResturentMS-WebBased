<?php

namespace App\Actions;

use App\Enums\CashCountType;
use App\Enums\ShiftStatus;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Support\Activity;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a shift on a counter for the signed-in cashier. The business date is fixed now
 * (PLAN §6). One open shift per counter and per cashier — checked under a branch lock,
 * and by unique generated columns in MySQL as a last line of defence.
 */
class OpenShift
{
    /**
     * @param  array<string, int>  $count  denomination => quantity (empty = amount typed in)
     * @param  list<int>  $employeeIds  staff on duty
     */
    public function handle(
        Admin $cashier,
        CashCounter $counter,
        ?ShiftType $type,
        float $openingCash,
        array $count = [],
        ?string $notes = null,
        array $employeeIds = [],
    ): Shift {
        return DB::transaction(function () use ($cashier, $counter, $type, $openingCash, $count, $notes, $employeeIds) {
            // serialises shift numbers and the "one open shift" checks of the branch
            Branch::query()->whereKey($counter->branch_id)->lockForUpdate()->first();

            $busy = Shift::query()->open()->where('cash_counter_id', $counter->id)->with('openedBy')->first();
            if ($busy) {
                throw ValidationException::withMessages([
                    'counter' => "“{$counter->name}” already has shift {$busy->code()} open ({$busy->openedBy?->name}).",
                ]);
            }

            $mine = Shift::query()->open()->where('opened_by', $cashier->id)->with('counter')->first();
            if ($mine) {
                throw ValidationException::withMessages([
                    'counter' => "You already have shift {$mine->code()} open on “{$mine->counter?->name}” — close it first.",
                ]);
            }

            if ($count) {
                $openingCash = round(array_sum(array_map(fn ($d, $q) => (float) $d * $q, array_keys($count), $count)), 2);
            }

            $shift = Shift::create([
                'branch_id' => $counter->branch_id,
                'number' => (int) Shift::query()->withoutGlobalScope('branch')->where('branch_id', $counter->branch_id)->max('number') + 1,
                'cash_counter_id' => $counter->id,
                'shift_type_id' => $type?->id,
                'business_date' => BusinessDate::for($counter->branch_id),
                'status' => ShiftStatus::Open,
                'opened_by' => $cashier->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'notes' => $notes,
            ]);

            foreach ($count as $denomination => $quantity) {
                if ($quantity > 0) {
                    $shift->counts()->create([
                        'type' => CashCountType::Opening,
                        'denomination' => $denomination,
                        'quantity' => $quantity,
                        'amount' => round((float) $denomination * $quantity, 2),
                    ]);
                }
            }

            foreach (array_unique($employeeIds) as $employeeId) {
                $shift->staff()->create(['employee_id' => $employeeId, 'checked_in_at' => now()]);
            }

            $shift->setRelation('counter', $counter);

            Activity::log('shift_opened', $shift, array_filter([
                'counter' => $counter->name,
                'shift_type' => $type?->name,
                'business_date' => $shift->business_date->toDateString(),
                'opening_cash' => $openingCash,
            ], fn ($v) => $v !== null));

            return $shift;
        });
    }
}
