<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DeliveryZoneResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'fee' => $this->fee,
            'min_order_amount' => $this->min_order_amount,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'deliveries_count' => $this->whenCounted('deliveries'),
            $this->trashFields(),
        ];
    }
}
