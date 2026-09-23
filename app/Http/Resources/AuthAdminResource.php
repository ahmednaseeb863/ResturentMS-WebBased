<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** The signed-in admin, shared with every page as `auth.user`. */
class AuthAdminResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'username' => $this->username,
            'initials' => $this->initials(),
            'role' => $this->is_super_admin ? 'Super Admin' : $this->role?->name,
            'is_super_admin' => $this->is_super_admin,
        ];
    }
}
