<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** A supplier. `balance` / purchase counts when the controller adds them (`withSum`, `balance`). */
class SupplierResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'ntn' => $this->ntn,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'purchases_count' => $this->whenCounted('purchases'),
            'balance' => $this->when(isset($this->balance), fn () => $this->balance),
            $this->trashFields(),
        ];
    }
}
