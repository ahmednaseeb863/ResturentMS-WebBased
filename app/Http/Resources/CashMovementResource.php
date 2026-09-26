<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** A cash in / out line of a shift. Load `admin` (and `rider`). */
class CashMovementResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'created_at' => static::iso($this->created_at),
            'type' => ['value' => $this->type->value, 'label' => $this->type->label(), 'tone' => $this->type->tone()],
            'direction' => $this->type->direction(),
            'amount' => $this->amount,
            'reason' => $this->reason,
            'rider' => $this->ref('rider'),
            'admin' => $this->admin?->name,
        ];
    }
}
