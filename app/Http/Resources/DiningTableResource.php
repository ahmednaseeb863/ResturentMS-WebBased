<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DiningTableResource extends Resource
{
    public function toArray(Request $request): array
    {
        [$w, $h] = $this->shape->size();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'area' => $this->ref('area'),
            'capacity' => $this->capacity,
            'shape' => $this->shape->value,
            'shape_label' => $this->shape->label(),
            'w' => $w,
            'h' => $h,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'pos_x' => $this->pos_x,
            'pos_y' => $this->pos_y,
            'is_active' => $this->is_active,
            $this->trashFields(),
        ];
    }
}
