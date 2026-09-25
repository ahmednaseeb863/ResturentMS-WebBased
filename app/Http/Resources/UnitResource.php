<?php

namespace App\Http\Resources;

use App\Support\Qty;
use Illuminate\Http\Request;

class UnitResource extends Resource
{
    public function toArray(Request $request): array
    {
        $base = $this->resource->relationLoaded('baseUnit') ? $this->baseUnit : null;

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'base_unit' => $this->ref('baseUnit', ['name', 'short_name']),
            'factor' => $base ? Qty::formatPrecise($this->factor) : null,
            'conversion' => $base ? "1 {$this->short_name} = ".Qty::formatPrecise($this->factor)." {$base->short_name}" : null,
            $this->trashFields(),
        ];
    }
}
