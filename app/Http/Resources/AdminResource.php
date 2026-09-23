<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'initials' => $this->initials(),
            'is_super_admin' => $this->is_super_admin,
            'is_active' => $this->is_active,
            'has_pin' => $this->pin !== null,
            'last_login_at' => static::iso($this->last_login_at),
            'role' => $this->ref('role'),
            'branches' => $this->refs('branches', ['name', 'code']),
            $this->trashFields(),
        ];
    }
}
