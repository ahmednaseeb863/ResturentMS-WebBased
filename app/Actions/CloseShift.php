<?php

namespace App\Actions;

use App\Enums\CashCountType;
use App\Enums\ShiftStatus;
use App\Models\Delivery;
use App\Models\Shift;
use App\Support\Activity;
use App\Support\ManagerApproval;
use App\Support\ShiftSummary;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a shift with the counted cash (PLAN §6): stores expected / counted / difference,
 * the float left in the drawer for the next shift and the cash handed over. A difference
 * above `shifts.max_difference` needs a manager PIN. Staff still on duty are checked out.
 * Cash riders still hold (PLAN §4.14) blocks the close unless a manager carries it over
 * (the closer is a shift manager, or a manager PIN).
 */
class CloseShift
{
    /** @param  array<string, int>  $count  denomination => quantity */
    public function handle(Shift $shift, float $counted, array $count, float $floatLeft, ?string $notes = null, ?string $pin = null, bool $carryRiderCash = false): Shift
    {
        // the PIN is checked outside the transaction so failed attempts count (rate limiter in the DB cache)
        $needsPin = $this->overLimit($shift, $counted) || ($carryRiderCash && ! $this->closerIsManager() && Delivery::cashHeld($shift->branch_id) > 0);
        $approver = $needsPin && $pin !== null && $pin !== ''
            ? ManagerApproval::verify($pin, Shift::MANAGER_ROUTE, $shift->branch)
            : null;

        return DB::transaction(function () use ($shift, $counted, $count, $floatLeft, $notes, $approver, $carryRiderCash) {
            $locked = Shift::query()->withoutGlobalScope('branch')->with('counter')->lockForUpdate()->findOrFail($shift->id);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['counted_cash' => "Shift {$locked->code()} is already closed."]);
            }

            $riderCash = Delivery::cashHeld($locked->branch_id);
            if ($riderCash > 0) {
                $held = money($riderCash, $locked->branch_id);
                if (! $carryRiderCash) {
                    throw ValidationException::withMessages(['rider_cash' => "Riders still hold {$held} — settle it on the Riders screen, or a manager carries it over."]);
                }
                if (! $this->closerIsManager() && ! $approver) {
                    throw ValidationException::withMessages(['pin' => "Carrying over the {$held} riders hold needs a manager PIN."]);
                }
            }

            $expected = ShiftSummary::of($locked)->expectedCash();
            $difference = round($counted - $expected, 2);

            if ($this->overLimit($locked, $counted, $expected) && ! $approver) {
                $limit = (float) setting('shifts.max_difference', $locked->branch_id);

                throw ValidationException::withMessages([
                    'pin' => setting('shifts.blind_close', $locked->branch_id)
                        ? 'The count does not match the drawer. Recount, or ask a manager to enter their PIN.'
                        : 'The count is '.($difference > 0 ? 'over' : 'short').' by '.money(abs($difference), $locked->branch_id)
                            .' — more than the '.money($limit, $locked->branch_id).' allowed. Recount, or ask a manager to enter their PIN.',
                ]);
            }

            $locked->update([
                'status' => ShiftStatus::Closed,
                'closed_by' => Auth::guard('admin')->id(),
                'closed_at' => now(),
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => $difference,
                'float_left' => $floatLeft,
                'handed_over_amount' => round($counted - $floatLeft, 2),
                'closing_notes' => $notes,
                'approved_by' => $approver?->id,
                'rider_cash_carried' => $riderCash > 0 ? $riderCash : null,
            ]);

            foreach ($count as $denomination => $quantity) {
                if ($quantity > 0) {
                    $locked->counts()->create([
                        'type' => CashCountType::Closing,
                        'denomination' => $denomination,
                        'quantity' => $quantity,
                        'amount' => round((float) $denomination * $quantity, 2),
                    ]);
                }
            }

            $locked->staff()->whereNull('checked_out_at')->update(['checked_out_at' => now()]);

            // raw materials nobody confirmed (Inventory setting)
            app(AutoConfirmConsumption::class)->forBranch($locked->branch_id);

            Activity::log('shift_closed', $locked, array_filter([
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => $difference,
                'float_left' => $floatLeft,
                'handed_over' => round($counted - $floatLeft, 2),
                'approved_by' => $approver?->name,
                'rider_cash_carried' => $riderCash > 0 ? $riderCash : null,
            ], fn ($v) => $v !== null));

            return $locked;
        });
    }

    /** Shift managers carry rider cash over without a PIN. */
    private function closerIsManager(): bool
    {
        return (bool) Auth::guard('admin')->user()?->canRoute(Shift::MANAGER_ROUTE);
    }

    /** Is the difference above `shifts.max_difference` (a manager must approve)? */
    public function overLimit(Shift $shift, float $counted, ?float $expected = null): bool
    {
        $expected ??= ShiftSummary::of($shift)->expectedCash();

        return abs(round($counted - $expected, 2)) > (float) setting('shifts.max_difference', $shift->branch_id);
    }
}
