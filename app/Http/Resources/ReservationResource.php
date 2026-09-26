<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;

/** A reservation; load `table`, `customer`, `createdBy`, `closedBy`. Times are the branch's local clock. */
class ReservationResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var Reservation $r */
        $r = $this->resource;

        return [
            'id' => $r->uuid,
            'code' => $r->code(),
            'guest_name' => $r->guest_name,
            'guest_phone' => $r->guest_phone,
            'customer' => $this->ref('customer', ['name', 'phone']),
            'party_size' => $r->party_size,
            'date' => $r->reserved_at->toDateString(),
            'time' => $r->reserved_at->format('H:i'),
            'ends' => $r->endsAt()->format('H:i'),
            'duration_minutes' => $r->duration_minutes,
            'table' => $this->ref('table', ['name', 'capacity']),
            'status' => ['value' => $r->status->value, 'label' => $r->status->label(), 'tone' => $r->status->tone()],
            'upcoming' => $r->status->isUpcoming(),
            'notes' => $r->notes,
            'created_by' => $this->ref('createdBy'),
            'created_at' => static::iso($r->created_at),
            'closed_by' => $this->ref('closedBy'),
            'close_reason' => $r->close_reason,
        ];
    }
}
