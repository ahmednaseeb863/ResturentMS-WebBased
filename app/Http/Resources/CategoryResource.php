<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CategoryResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'image_url' => $this->imageUrl(),
            'kitchen_station' => $this->ref('kitchenStation'),
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'menu_items_count' => $this->whenCounted('menuItems'),
            'ready_items_count' => $this->whenCounted('readyItems'),
            $this->trashFields(),
        ];
    }
}
