<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Models\Shift;
use App\Support\Activity;
use App\Support\ShiftSummary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash in / out of an open shift's drawer (append-only). Takes the shift's business date.
 * Money going out may not be more than the cash in the drawer. Later modules (expenses,
 * rider settlements, supplier payments) record through here with their `reference`.
 */
class RecordCashMovement
{
    public function handle(
        Shift $shift,
        CashMovementType $type,
        float $amount,
        ?string $reason = null,
        ?Model $reference = null,
        ?int $riderId = null,
    ): CashMovement {
        return DB::transaction(function () use ($shift, $type, $amount, $reason, $reference, $riderId) {
            $locked = Shift::query()->withoutGlobalScope('branch')->lockForUpdate()->findOrFail($shift->id);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['amount' => "Shift {$locked->code()} is closed."]);
            }

            if ($type->direction() < 0) {
                $inDrawer = ShiftSummary::of($locked)->expectedCash();

                if (round($amount, 2) > $inDrawer) {
                    throw ValidationException::withMessages([
                        'amount' => setting('shifts.blind_close', $locked->branch_id)
                            ? 'That is more than the cash in the drawer.'
                            : 'Only '.money($inDrawer, $locked->branch_id).' is in the drawer.',
                    ]);
                }
            }

            $movement = CashMovement::create([
                'branch_id' => $locked->branch_id,
                'shift_id' => $locked->id,
                'business_date' => $locked->business_date,
                'type' => $type,
                'amount' => round($amount, 2),
                'reason' => $reason,
                'rider_id' => $riderId,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'admin_id' => Auth::guard('admin')->id(),
            ]);

            Activity::log('cash_movement', $shift, array_filter([
                'type' => $type->label(),
                'amount' => round($amount, 2),
                'reason' => $reason,
            ], fn ($v) => $v !== null));

            return $movement;
        });
    }
}
