<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRecipes;
use App\Models\ModifierGroup;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A modifier group with its add-ons. Add-ons are sent as a list: existing ones carry
 * their uuid, new ones none; the ones left out are trashed. Each add-on may use raw
 * materials (Extra cheese → cheese 30 g).
 */
class ModifierGroupRequest extends FormRequest
{
    use ValidatesRecipes;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', new UniqueWithTrash('modifier_groups', 'name', $this->group()?->id, 'modifier group', ['branch_id' => app(CurrentBranch::class)->id()])],
            'min_select' => ['required', 'integer', 'min:0', 'max:20'],
            'max_select' => ['nullable', 'integer', 'min:1', 'max:50'],
            'is_active' => ['boolean'],
            'modifiers' => ['required', 'array', 'min:1', 'max:40'],
            'modifiers.*.id' => ['nullable', 'uuid'],
            'modifiers.*.name' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'modifiers.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'modifiers.*.is_active' => ['boolean'],
            ...$this->recipeRules(),
        ];
    }

    public function attributes(): array
    {
        return [
            'modifiers.*.name' => 'name',
            'modifiers.*.price' => 'price',
            ...$this->recipeAttributes(),
        ];
    }

    public function messages(): array
    {
        return [
            'modifiers.required' => 'Add at least one option.',
            'modifiers.*.name.distinct' => 'Two options have the same name.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $min = (int) $this->input('min_select');
            $max = $this->filled('max_select') ? (int) $this->input('max_select') : null;
            $count = count((array) $this->input('modifiers', []));

            if ($max !== null && $max < $min) {
                $validator->errors()->add('max_select', 'Maximum cannot be less than the minimum.');
            }
            if ($min > $count) {
                $validator->errors()->add('min_select', "Only {$count} option".($count === 1 ? '' : 's').' to pick from.');
            }

            $known = $this->group()?->modifiers()->pluck('uuid')->all() ?? [];
            foreach ((array) $this->input('modifiers', []) as $i => $modifier) {
                if (! empty($modifier['id']) && ! in_array($modifier['id'], $known, true)) {
                    $validator->errors()->add("modifiers.{$i}.name", 'This option no longer exists — reload the page.');
                }
            }

            $this->checkRecipes($validator);
        }];
    }

    protected function recipePaths(): array
    {
        return ['modifiers.*.recipe'];
    }

    public function group(): ?ModifierGroup
    {
        return $this->route('modifier_group');
    }

    public function groupData(): array
    {
        return [
            'name' => $this->validated('name'),
            'min_select' => (int) $this->validated('min_select'),
            'max_select' => $this->filled('max_select') ? (int) $this->validated('max_select') : null,
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    /** @return list<array{id: ?string, name: string, price: string, is_active: bool, recipe: list<array>}> */
    public function modifiers(): array
    {
        return array_map(fn (array $m) => [
            'id' => $m['id'] ?? null,
            'name' => $m['name'],
            'price' => $m['price'],
            'is_active' => filter_var($m['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            'recipe' => $this->recipeLines($m['recipe'] ?? []),
        ], array_values($this->validated('modifiers')));
    }
}
