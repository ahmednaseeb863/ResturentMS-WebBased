<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Http\Requests\Concerns\HandlesImage;
use App\Http\Requests\Concerns\ValidatesStockUnits;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReadyItemRequest extends FormRequest
{
    use HandlesImage, ValidatesStockUnits;

    public function rules(): array
    {
        $branch = ['branch_id' => app(CurrentBranch::class)->id()];
        $ignore = $this->stockItem()?->id;

        return [
            'name' => ['required', 'string', 'max:120', new UniqueWithTrash('ready_items', 'name', $ignore, 'ready item', $branch)],
            'code' => ['nullable', 'string', 'max:30', new UniqueWithTrash('ready_items', 'code', $ignore, 'ready item', $branch)],
            'barcode' => ['nullable', 'string', 'max:60', new UniqueWithTrash('ready_items', 'barcode', $ignore, 'ready item', $branch)],
            'category' => ['required', 'uuid'],
            'kitchen_station' => ['nullable', 'uuid'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'available_for' => ['required', 'array', 'min:1'],
            'available_for.*' => [Rule::in(OrderType::values())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            ...$this->stockRules(),
            ...$this->imageRules(),
        ];
    }

    public function messages(): array
    {
        return ['available_for.required' => 'Pick at least one order type.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('category') && ! $this->category()) {
                $validator->errors()->add('category', 'Pick a category of this branch.');
            }
            if ($this->filled('kitchen_station') && ! $this->station()) {
                $validator->errors()->add('kitchen_station', 'Pick a kitchen station of this branch.');
            }

            $this->checkStockUnits($validator);
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? strtoupper(trim($this->input('code'))) : null,
            'barcode' => $this->filled('barcode') ? trim($this->input('barcode')) : null,
        ]);
    }

    public function stockItem(): ?Model
    {
        return $this->route('ready_item');
    }

    public function category(): ?Category
    {
        return once(fn () => Category::query()->where('uuid', $this->input('category'))->first());
    }

    public function station(): ?KitchenStation
    {
        return once(fn () => KitchenStation::query()->where('uuid', $this->input('kitchen_station'))->first());
    }

    public function itemData(): array
    {
        return [
            'name' => $this->validated('name'),
            'code' => $this->validated('code'),
            'barcode' => $this->validated('barcode'),
            'category_id' => $this->category()->id,
            'kitchen_station_id' => $this->filled('kitchen_station') ? $this->station()->id : null,
            'price' => $this->validated('price'),
            'available_for' => array_values(array_unique($this->validated('available_for'))),
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
            'is_active' => $this->boolean('is_active', true),
            ...$this->stockData(),
            ...$this->imageData('ready-items'),
        ];
    }
}
