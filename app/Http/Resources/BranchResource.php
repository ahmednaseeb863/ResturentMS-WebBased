<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class BranchResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_number' => $this->tax_number,
            'is_active' => $this->is_active,
            'manager' => $this->ref('manager'),
            'admins_count' => $this->whenCounted('admins'),
            'created_at' => static::iso($this->created_at),
            $this->trashFields(),
        ];
    }
}
