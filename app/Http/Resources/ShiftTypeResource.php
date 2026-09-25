<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ShiftTypeResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'start_time' => $this->startsAt(),
            'end_time' => $this->endsAt(),
            'overnight' => $this->isOvernight(),
            'duration_minutes' => $this->durationMinutes(),
            'is_active' => $this->is_active,
            $this->trashFields(),
        ];
    }
}
