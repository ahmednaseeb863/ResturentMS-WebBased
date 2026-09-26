<?php

namespace App\Actions;

use App\Enums\CashCountType;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Support\Activity;
use App\Support\ManagerApproval;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reopens a closed shift (shift managers only, logged). Only the counter's latest shift,
 * and only while its cashier has no other shift open. The closing figures are cleared
 * (kept in the activity log) and the closing count is trashed. The business date stays.
 */
class ReopenShift
{
    public function handle(Shift $shift, string $reason, ?string $pin = null): Shift
    {
        // checked outside the transaction so failed attempts count (rate limiter in the DB cache)
        $approver = setting('approvals.pin_reopen_shift', $shift->branch_id)
            ? ManagerApproval::verify($pin, Shift::MANAGER_ROUTE, $shift->branch)
            : null;

        return DB::transaction(function () use ($shift, $reason, $approver) {
            $locked = Shift::query()->withoutGlobalScope('branch')->with(['counter', 'openedBy'])->lockForUpdate()->findOrFail($shift->id);

            if ($locked->isOpen()) {
                throw ValidationException::withMessages(['reason' => "Shift {$locked->code()} is already open."]);
            }

            $newer = Shift::query()->withoutGlobalScope('branch')
                ->where('cash_counter_id', $locked->cash_counter_id)
                ->where('number', '>', $locked->number)
                ->orderBy('number')
                ->first();
            if ($newer) {
                throw ValidationException::withMessages([
                    'reason' => "Shift {$newer->code()} has already run on “{$locked->counter?->name}” — only the latest shift of a counter can be reopened.",
                ]);
            }

            $other = Shift::query()->withoutGlobalScope('branch')->open()
                ->where('branch_id', $locked->branch_id)
                ->where('opened_by', $locked->opened_by)
                ->with('counter')
                ->first();
            if ($other) {
                throw ValidationException::withMessages([
                    'reason' => "{$locked->openedBy?->name} has shift {$other->code()} open on “{$other->counter?->name}” — close it first.",
                ]);
            }

            $before = [
                'counted_cash' => (float) $locked->counted_cash,
                'difference' => (float) $locked->difference,
            ];

            $locked->update([
                'status' => ShiftStatus::Open,
                'closed_by' => null,
                'closed_at' => null,
                'expected_cash' => null,
                'counted_cash' => null,
                'difference' => null,
                'float_left' => null,
                'handed_over_amount' => null,
                'closing_notes' => null,
                'approved_by' => null,
                'reopened_by' => Auth::guard('admin')->id(),
                'reopened_at' => now(),
                'reopen_count' => $locked->reopen_count + 1,
            ]);

            $locked->counts()->where('type', CashCountType::Closing)->get()->each->trash('Shift reopened');

            Activity::log('shift_reopened', $locked, array_filter([
                'reason' => $reason,
                'was_counted' => $before['counted_cash'],
                'was_difference' => $before['difference'],
                'approved_by' => $approver?->name,
            ], fn ($v) => $v !== null));

            return $locked;
        });
    }
}
