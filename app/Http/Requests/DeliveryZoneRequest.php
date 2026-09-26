<?php

namespace App\Http\Requests;

use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;

class DeliveryZoneRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', new UniqueWithTrash('delivery_zones', 'name', $this->route('delivery_zone')?->id, 'zone', ['branch_id' => app(CurrentBranch::class)->id()])],
            'fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    public function zoneData(): array
    {
        return [
            'name' => $this->validated('name'),
            'fee' => round((float) $this->validated('fee'), 2),
            'min_order_amount' => $this->filled('min_order_amount') ? round((float) $this->validated('min_order_amount'), 2) : null,
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
