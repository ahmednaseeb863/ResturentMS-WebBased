<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CustomerResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'initials' => $this->initials(),
            'phone' => $this->phone,
            'email' => $this->email,
            'birthday' => $this->birthday?->toDateString(),
            'notes' => $this->notes,
            'total_spent' => $this->total_spent,
            'visits_count' => $this->visits_count,
            'last_visit_at' => static::iso($this->last_visit_at),
            'created_at' => static::iso($this->created_at),
            'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses->map(fn ($a) => [
                'id' => $a->uuid,
                'label' => $a->label,
                'address' => $a->address,
                'area' => $a->area,
                'landmark' => $a->landmark,
                'is_default' => $a->is_default,
            ])->values()),
            $this->trashFields(),
        ];
    }
}
