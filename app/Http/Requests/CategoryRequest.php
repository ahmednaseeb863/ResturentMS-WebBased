<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HandlesImage;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CategoryRequest extends FormRequest
{
    use HandlesImage;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', new UniqueWithTrash('categories', 'name', $this->category()?->id, 'category', ['branch_id' => app(CurrentBranch::class)->id()])],
            'kitchen_station' => ['nullable', 'uuid'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            ...$this->imageRules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('kitchen_station') && ! $this->station()) {
                $validator->errors()->add('kitchen_station', 'Pick a kitchen station of this branch.');
            }
        }];
    }

    public function category(): ?Category
    {
        return $this->route('category');
    }

    public function station(): ?KitchenStation
    {
        return once(fn () => KitchenStation::query()->where('uuid', $this->input('kitchen_station'))->first());
    }

    public function categoryData(): array
    {
        return [
            'name' => $this->validated('name'),
            'kitchen_station_id' => $this->filled('kitchen_station') ? $this->station()->id : null,
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
            'is_active' => $this->boolean('is_active', true),
            ...$this->imageData('categories'),
        ];
    }
}
