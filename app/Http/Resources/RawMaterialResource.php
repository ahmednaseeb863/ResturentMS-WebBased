<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class RawMaterialResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => 'raw_material',
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->ref('category'),
            ...StockFields::of($this->resource),
            'is_active' => $this->is_active,
            $this->trashFields(),
        ];
    }
}
