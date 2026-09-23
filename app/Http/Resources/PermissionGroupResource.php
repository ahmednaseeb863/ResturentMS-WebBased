<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** A group of tick-boxes in the role editor. */
class PermissionGroupResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'permissions' => $this->refs('permissions', ['title']),
        ];
    }
}
