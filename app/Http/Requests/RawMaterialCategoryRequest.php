<?php

namespace App\Http\Requests;

use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;

class RawMaterialCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', new UniqueWithTrash('raw_material_categories', 'name', $this->route('raw_material_category')?->id, 'category', ['branch_id' => app(CurrentBranch::class)->id()])],
        ];
    }
}
