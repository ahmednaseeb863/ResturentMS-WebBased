<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesStockUnits;
use App\Models\RawMaterialCategory;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RawMaterialRequest extends FormRequest
{
    use ValidatesStockUnits;

    public function rules(): array
    {
        $branch = ['branch_id' => app(CurrentBranch::class)->id()];
        $ignore = $this->stockItem()?->id;

        return [
            'name' => ['required', 'string', 'max:120', new UniqueWithTrash('raw_materials', 'name', $ignore, 'raw material', $branch)],
            'code' => ['nullable', 'string', 'max:30', new UniqueWithTrash('raw_materials', 'code', $ignore, 'raw material', $branch)],
            'category' => ['nullable', 'uuid'],
            'is_active' => ['boolean'],
            ...$this->stockRules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('category') && ! $this->category()) {
                $validator->errors()->add('category', 'Pick a category of this branch.');
            }

            $this->checkStockUnits($validator);
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => $this->filled('code') ? strtoupper(trim($this->input('code'))) : null]);
    }

    public function stockItem(): ?Model
    {
        return $this->route('raw_material');
    }

    public function category(): ?RawMaterialCategory
    {
        return once(fn () => RawMaterialCategory::query()->where('uuid', $this->input('category'))->first());
    }

    public function materialData(): array
    {
        return [
            'name' => $this->validated('name'),
            'code' => $this->validated('code'),
            'category_id' => $this->filled('category') ? $this->category()->id : null,
            'is_active' => $this->boolean('is_active', true),
            ...$this->stockData(),
        ];
    }
}
