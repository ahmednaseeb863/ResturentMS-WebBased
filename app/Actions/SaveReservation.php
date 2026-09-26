<?php

namespace App\Actions;

use App\Enums\ReservationStatus;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Reservation;
use App\Support\Activity;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a reservation (PLAN §4.17). Only upcoming ones (pending / confirmed) can be
 * edited. A table can't be booked twice for overlapping times. A phone that belongs to a
 * customer links the reservation to them.
 *
 * `$data`: customer (?Customer), guest_name, guest_phone, party_size, reserved_at
 * (CarbonImmutable, branch local time), duration_minutes, table (?DiningTable), notes, confirmed.
 */
class SaveReservation
{
    public function handle(?Reservation $reservation, array $data, Admin $admin): Reservation
    {
        return DB::transaction(function () use ($reservation, $data, $admin) {
            $branchId = app(CurrentBranch::class)->id();

            if ($reservation) {
                $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
                if (! $reservation->status->isUpcoming()) {
                    throw ValidationException::withMessages(['reservation' => "{$reservation->code()} is {$reservation->status->label()} — it can't be changed."]);
                }
            } elseif ($data['reserved_at']->lt(Reservation::localNow($branchId)->subMinutes(30))) {
                throw ValidationException::withMessages(['date' => 'That time has already passed.']);
            }

            $phone = Customer::normalizePhone($data['guest_phone'] ?? null);
            $customer = $data['customer'] ?? ($phone ? Customer::query()->where('phone', $phone)->first() : null);

            if ($data['table']) {
                $this->checkFree($data['table'], $data['reserved_at'], $data['duration_minutes'], $reservation);
            }

            $values = [
                'user_id' => $customer?->id,
                'guest_name' => $data['guest_name'],
                'guest_phone' => $phone ?? $customer?->phone,
                'party_size' => $data['party_size'],
                'reserved_at' => $data['reserved_at'],
                'duration_minutes' => $data['duration_minutes'],
                'table_id' => $data['table']?->id,
                'notes' => $data['notes'],
            ];

            if ($reservation) {
                $reservation->fill($values);
                if ($data['confirmed'] && $reservation->status === ReservationStatus::Pending) {
                    $reservation->fill(['status' => ReservationStatus::Confirmed, 'confirmed_at' => now()]);
                }
                $changed = array_keys($reservation->getDirty());
                $reservation->save();
                Activity::log('reservation_updated', $reservation, ['changed' => $changed ?: null]);

                return $reservation;
            }

            $reservation = Reservation::create([
                ...$values,
                'branch_id' => $branchId,
                'number' => Reservation::nextNumber($branchId),
                'status' => $data['confirmed'] ? ReservationStatus::Confirmed : ReservationStatus::Pending,
                'confirmed_at' => $data['confirmed'] ? now() : null,
                'created_by' => $admin->id,
            ]);

            Activity::log('reservation_added', $reservation, array_filter([
                'guest' => $reservation->guest_name,
                'at' => $reservation->reserved_at->format('Y-m-d H:i'),
                'party' => $reservation->party_size,
                'table' => $data['table']?->name,
            ]));

            return $reservation;
        });
    }

    private function checkFree(DiningTable $table, CarbonImmutable $start, int $minutes, ?Reservation $self): void
    {
        $end = $start->addMinutes($minutes);

        $clash = Reservation::query()
            ->where('table_id', $table->id)
            ->upcoming()
            ->when($self, fn ($q) => $q->whereKeyNot($self->id))
            ->where('reserved_at', '<', $end->format('Y-m-d H:i:s'))
            ->whereRaw('DATE_ADD(reserved_at, INTERVAL duration_minutes MINUTE) > ?', [$start->format('Y-m-d H:i:s')])
            ->lockForUpdate()
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'table' => "{$table->name} is booked {$clash->reserved_at->format('H:i')}–{$clash->endsAt()->format('H:i')} ({$clash->code()}, {$clash->guest_name}).",
            ]);
        }
    }
}
