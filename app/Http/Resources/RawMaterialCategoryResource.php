<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class RawMaterialCategoryResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'raw_materials_count' => $this->whenCounted('rawMaterials'),
            $this->trashFields(),
        ];
    }
}
