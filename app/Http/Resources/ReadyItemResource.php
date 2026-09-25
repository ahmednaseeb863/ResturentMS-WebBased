<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ReadyItemResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => 'ready_item',
            'code' => $this->code,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'image_url' => $this->imageUrl(),
            'price' => $this->price,
            'category' => $this->ref('category'),
            'kitchen_station' => $this->ref('kitchenStation'),
            'available_for' => $this->available_for,
            'sort_order' => $this->sort_order,
            ...StockFields::of($this->resource),
            'is_active' => $this->is_active,
            $this->trashFields(),
        ];
    }
}
