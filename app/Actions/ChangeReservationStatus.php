<?php

namespace App\Actions;

use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Admin;
use App\Models\Reservation;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a reservation on (PLAN §4.17):
 *   confirm — pending → confirmed
 *   seat    — the guests arrived; a table held as "reserved" becomes available for their order
 *   cancel  — needs a reason
 *   no_show — only once the booked time has come
 */
class ChangeReservationStatus
{
    public const ACTIONS = ['confirm', 'seat', 'cancel', 'no_show'];

    public function handle(Reservation $reservation, string $action, ?string $reason, Admin $admin): Reservation
    {
        return DB::transaction(function () use ($reservation, $action, $reason, $admin) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $fail = fn (string $message) => throw ValidationException::withMessages(['reservation' => $message]);

            if (! $reservation->status->isUpcoming()) {
                $fail("{$reservation->code()} is already {$reservation->status->label()}.");
            }

            switch ($action) {
                case 'confirm':
                    if ($reservation->status === ReservationStatus::Confirmed) {
                        $fail("{$reservation->code()} is already confirmed.");
                    }
                    $reservation->update(['status' => ReservationStatus::Confirmed, 'confirmed_at' => now()]);
                    break;

                case 'seat':
                    $reservation->update(['status' => ReservationStatus::Seated, 'seated_at' => now()]);
                    $table = $reservation->table;
                    if ($table && $table->status === TableStatus::Reserved) {
                        $table->update(['status' => TableStatus::Available]);
                    }
                    break;

                case 'cancel':
                case 'no_show':
                    if ($action === 'cancel' && blank($reason)) {
                        throw ValidationException::withMessages(['reason' => 'Say why it is cancelled.']);
                    }
                    if ($action === 'no_show' && $reservation->reserved_at->gt(Reservation::localNow($reservation->branch_id))) {
                        $fail('The booked time has not come yet.');
                    }
                    $reservation->update([
                        'status' => $action === 'cancel' ? ReservationStatus::Cancelled : ReservationStatus::NoShow,
                        'closed_at' => now(),
                        'closed_by' => $admin->id,
                        'close_reason' => $reason,
                    ]);
                    break;

                default:
                    $fail('Unknown action.');
            }

            Activity::log("reservation_{$action}", $reservation, array_filter(['reason' => $reason]));

            return $reservation;
        });
    }
}
