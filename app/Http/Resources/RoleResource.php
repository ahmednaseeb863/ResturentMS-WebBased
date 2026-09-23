<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class RoleResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'admins_count' => $this->whenCounted('admins'),
            // permission uuids, for the role editor tick-boxes
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('uuid')->values()),
            'updated_at' => static::iso($this->updated_at),
            $this->trashFields(),
        ];
    }
}
