<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class KitchenStationResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'has_screen' => $this->has_screen,
            'printer' => $this->ref('printer', ['name', 'is_active']),
            'is_active' => $this->is_active,
            'categories_count' => $this->whenCounted('categories'),
            'items_count' => $this->when(isset($this->menu_items_count), fn () => $this->menu_items_count + $this->ready_items_count),
            $this->trashFields(),
        ];
    }
}
