<?php

namespace App\Http\Resources;

use App\Support\BusinessDate;
use Illuminate\Http\Request;

class DiscountResource extends Resource
{
    public function toArray(Request $request): array
    {
        $status = $this->statusOn(BusinessDate::for());

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type->value,
            'value' => $this->value,
            'applies_to' => $this->applies_to->value,
            'applies_to_label' => $this->applies_to->label(),
            'max_amount' => $this->max_amount,
            'min_order_amount' => $this->min_order_amount,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'requires_approval' => $this->requires_approval,
            'is_active' => $this->is_active,
            'status' => ['value' => $status->value, 'label' => $status->label(), 'dot' => $status->dot()],
            $this->trashFields(),
        ];
    }
}
