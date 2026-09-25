<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AreaResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'tables_count' => $this->whenCounted('tables'),
            'seats' => $this->whenAggregated('tables', 'capacity', 'sum', fn ($v) => (int) $v),
            $this->trashFields(),
        ];
    }
}
