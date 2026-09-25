<?php

namespace App\Actions;

use App\Http\Requests\MenuItemRequest;
use App\Models\MenuItem;
use App\Models\MenuItemModifierGroup;
use App\Models\MenuItemVariant;
use App\Models\ModifierGroup;
use App\Support\Activity;
use App\Support\TrashablePivot;
use Illuminate\Support\Facades\DB;

/**
 * Saves a menu item with its recipe, variants (+ their own recipes) and add-on groups
 * in one transaction. Removed variants / recipe lines / group links are trashed.
 */
class SaveMenuItem
{
    public function handle(MenuItemRequest $request, ?MenuItem $item = null): MenuItem
    {
        return DB::transaction(function () use ($request, $item) {
            $item ??= new MenuItem;
            $item->fill($request->itemData())->save();

            $changes = [
                'recipe' => $item->syncRecipe($request->recipeLines($request->validated('recipe'))),
                ...$this->syncVariants($item, $request->variants()),
                ...$this->syncModifierGroups($item, $request),
            ];

            $changes = array_filter($changes);
            if ($changes && ! $item->wasRecentlyCreated) {
                Activity::log('menu_setup', $item, $changes);
            }

            return $item;
        });
    }

    private function syncVariants(MenuItem $item, array $variants): array
    {
        $existing = $item->variants()->get()->keyBy('uuid');
        $keep = [];
        $changes = ['sizes added' => [], 'sizes changed' => [], 'sizes removed' => [], 'size recipes' => []];

        foreach ($variants as $i => $data) {
            $variant = $data['id'] ? $existing->get($data['id']) : null;
            $fields = ['name' => $data['name'], 'price' => $data['price'], 'is_default' => $data['is_default'], 'sort_order' => $i];

            if ($variant) {
                $variant->fill($fields);
                if ($variant->isDirty(['name', 'price'])) {
                    $changes['sizes changed'][] = $variant->name;
                }
                $variant->save();
                $keep[] = $variant->uuid;
            } else {
                $variant = MenuItemVariant::create([...$fields, 'menu_item_id' => $item->id]);
                $changes['sizes added'][] = $variant->name;
            }

            foreach ($variant->syncRecipe($data['recipe']) as $line) {
                $changes['size recipes'][] = "{$variant->name}: {$line}";
            }
        }

        foreach ($existing->toBase()->except($keep) as $variant) {
            $variant->trash();
            $changes['sizes removed'][] = $variant->name;
        }

        return $changes;
    }

    private function syncModifierGroups(MenuItem $item, MenuItemRequest $request): array
    {
        $groups = $request->modifierGroups();
        $result = TrashablePivot::sync(MenuItemModifierGroup::class, 'menu_item_id', $item->id, 'modifier_group_id', $groups->pluck('id')->all());

        foreach ($groups->values() as $i => $group) {
            MenuItemModifierGroup::query()->where('menu_item_id', $item->id)->where('modifier_group_id', $group->id)->update(['sort_order' => $i]);
        }

        $names = fn (array $ids) => $groups->whereIn('id', $ids)->pluck('name')->all();
        $removed = $result['detached']
            ? ModifierGroup::query()->withTrashed()->whereIn('id', $result['detached'])->pluck('name')->all()
            : [];

        return ['add-ons linked' => $names($result['attached']), 'add-ons unlinked' => $removed];
    }
}
