<?php

namespace App\Http\Requests;

use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;

class AreaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', new UniqueWithTrash('areas', 'name', $this->route('area')?->id, 'area', ['branch_id' => app(CurrentBranch::class)->id()])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    public function areaData(): array
    {
        return [
            'name' => $this->validated('name'),
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
