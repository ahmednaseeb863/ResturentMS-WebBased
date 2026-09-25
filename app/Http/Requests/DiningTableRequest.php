<?php

namespace App\Http\Requests;

use App\Enums\TableShape;
use App\Models\Area;
use App\Models\DiningTable;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DiningTableRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:30', new UniqueWithTrash('tables', 'name', $this->table()?->id, 'table', ['branch_id' => app(CurrentBranch::class)->id()])],
            'area' => ['required', 'uuid'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'shape' => ['required', Rule::enum(TableShape::class)],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['capacity.min' => 'A table seats at least 1 guest.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('area') && ! $this->area()) {
                $validator->errors()->add('area', 'Pick an area of this branch.');
            }
        }];
    }

    public function table(): ?DiningTable
    {
        return $this->route('table');
    }

    public function area(): ?Area
    {
        return once(fn () => Area::query()->where('uuid', $this->input('area'))->first());
    }

    public function tableData(): array
    {
        return [
            'name' => $this->validated('name'),
            'area_id' => $this->area()->id,
            'capacity' => (int) $this->validated('capacity'),
            'shape' => $this->validated('shape'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
