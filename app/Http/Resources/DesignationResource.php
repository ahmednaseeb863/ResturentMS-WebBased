<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DesignationResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'default_role' => $this->ref('defaultRole'),
            'is_active' => $this->is_active,
            'employees_count' => $this->whenCounted('employees'),
            $this->trashFields(),
        ];
    }
}
