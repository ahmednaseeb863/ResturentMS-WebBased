<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsRecipe;
use App\Models\MenuItemVariant;
use Illuminate\Http\Request;

/**
 * Load `category.kitchenStation`, `kitchenStation`, `modifierGroups`, and the recipes:
 * `recipeItems.rawMaterial.stockUnit`, `recipeItems.unit`, `variants.recipeItems.…`.
 */
class MenuItemResource extends Resource
{
    use FormatsRecipe;

    public function toArray(Request $request): array
    {
        $itemCost = static::recipeCostOf($this->resource);
        $station = $this->kitchenStation ?? $this->category?->kitchenStation;

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
            'price' => $this->price,
            'category' => $this->ref('category'),
            'kitchen_station' => $this->ref('kitchenStation'),
            'station_name' => $station?->name,
            'prep_time_minutes' => $this->prep_time_minutes,
            'available_for' => $this->available_for,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_sold_out' => $this->is_sold_out,
            'recipe' => static::recipeOf($this->resource),
            'recipe_cost' => $itemCost,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn (MenuItemVariant $v) => [
                'id' => $v->uuid,
                'name' => $v->name,
                'price' => $v->price,
                'is_default' => $v->is_default,
                'recipe' => static::recipeOf($v),
                // own recipe replaces the item's
                'recipe_cost' => static::recipeCostOf($v) ?? $itemCost,
            ])->values()->all()),
            'modifier_groups' => $this->refs('modifierGroups'),
            $this->trashFields(),
        ];
    }
}
