<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Http\Requests\Concerns\HandlesImage;
use App\Http\Requests\Concerns\ValidatesRecipes;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Menu item with its recipe, variants (each with an optional own recipe) and linked
 * modifier groups. Variants are sent as a list: existing ones carry their uuid, new ones
 * none; the ones left out are trashed. With variants, the item price is the default
 * variant's price.
 */
class MenuItemRequest extends FormRequest
{
    use HandlesImage, ValidatesRecipes;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', new UniqueWithTrash('menu_items', 'name', $this->menuItem()?->id, 'menu item', ['branch_id' => app(CurrentBranch::class)->id()])],
            'category' => ['required', 'uuid'],
            'kitchen_station' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => [$this->hasVariants() ? 'nullable' : 'required', 'numeric', 'min:0', 'max:9999999'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'available_for' => ['required', 'array', 'min:1'],
            'available_for.*' => [Rule::in(OrderType::values())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'is_sold_out' => ['boolean'],
            'variants' => ['nullable', 'array', 'max:20'],
            'variants.*.id' => ['nullable', 'uuid'],
            'variants.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'variants.*.is_default' => ['boolean'],
            'modifier_groups' => ['nullable', 'array', 'max:20'],
            'modifier_groups.*' => ['uuid', 'distinct'],
            ...$this->recipeRules(),
            ...$this->imageRules(),
        ];
    }

    public function attributes(): array
    {
        return [
            'variants.*.name' => 'name',
            'variants.*.price' => 'price',
            ...$this->recipeAttributes(),
        ];
    }

    public function messages(): array
    {
        return [
            'available_for.required' => 'Pick at least one order type.',
            'variants.*.name.distinct' => 'Two sizes have the same name.',
        ];
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
            if ($this->modifierGroups()->count() !== count(array_unique((array) $this->input('modifier_groups', [])))) {
                $validator->errors()->add('modifier_groups', 'An add-on group no longer exists — reload the page.');
            }

            $known = $this->menuItem()?->variants()->pluck('uuid')->all() ?? [];
            foreach ((array) $this->input('variants', []) as $i => $variant) {
                if (! empty($variant['id']) && ! in_array($variant['id'], $known, true)) {
                    $validator->errors()->add("variants.{$i}.name", 'This size no longer exists — reload the page.');
                }
            }

            // a size fixed in a deal can't be removed
            $kept = array_filter(array_column((array) $this->input('variants', []), 'id'));
            $removed = $this->menuItem()?->variants()->whereNotIn('uuid', $kept)
                ->whereHas('dealOptions')->with('dealOptions.slot.deal')->get() ?? collect();
            foreach ($removed as $variant) {
                $deals = $variant->dealOptions->map(fn ($o) => $o->slot?->deal?->name)->filter()->unique()->values()->all();
                if ($deals) {
                    $validator->errors()->add('variants', "Size “{$variant->name}” — ".lcfirst(MenuItem::dealListReason($deals)));
                }
            }

            $this->checkRecipes($validator);
        }];
    }

    protected function recipePaths(): array
    {
        return ['recipe', 'variants.*.recipe'];
    }

    public function menuItem(): ?MenuItem
    {
        return $this->route('menu_item');
    }

    public function hasVariants(): bool
    {
        return count((array) $this->input('variants', [])) > 0;
    }

    public function category(): ?Category
    {
        return once(fn () => Category::query()->where('uuid', $this->input('category'))->first());
    }

    public function station(): ?KitchenStation
    {
        return once(fn () => KitchenStation::query()->where('uuid', $this->input('kitchen_station'))->first());
    }

    /** Picked groups of the current branch, keyed in the picked order. */
    public function modifierGroups(): Collection
    {
        return once(function () {
            $uuids = array_values(array_filter((array) $this->input('modifier_groups', []), 'is_string'));

            return ModifierGroup::query()->whereIn('uuid', $uuids)->get()
                ->sortBy(fn (ModifierGroup $g) => array_search($g->uuid, $uuids, true))->values();
        });
    }

    public function itemData(): array
    {
        $variants = $this->variants();
        $default = collect($variants)->firstWhere('is_default', true);

        return [
            'name' => $this->validated('name'),
            'category_id' => $this->category()->id,
            'kitchen_station_id' => $this->filled('kitchen_station') ? $this->station()->id : null,
            'description' => $this->validated('description'),
            'price' => $default ? $default['price'] : $this->validated('price'),
            'prep_time_minutes' => $this->validated('prep_time_minutes'),
            'available_for' => array_values(array_unique($this->validated('available_for'))),
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
            'is_active' => $this->boolean('is_active', true),
            'is_sold_out' => $this->boolean('is_sold_out'),
            ...$this->imageData('menu-items'),
        ];
    }

    /** Exactly one variant is the default when there are any. */
    public function variants(): array
    {
        $variants = array_values($this->validated('variants') ?? []);
        $default = collect($variants)->search(fn ($v) => filter_var($v['is_default'] ?? false, FILTER_VALIDATE_BOOL));
        $default = $default === false ? 0 : $default;

        return array_map(fn (array $v, int $i) => [
            'id' => $v['id'] ?? null,
            'name' => $v['name'],
            'price' => $v['price'],
            'is_default' => $i === $default,
            'recipe' => $this->recipeLines($v['recipe'] ?? []),
        ], $variants, array_keys($variants));
    }
}
