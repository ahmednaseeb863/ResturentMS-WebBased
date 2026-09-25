<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsRecipe;
use App\Models\Modifier;
use Illuminate\Http\Request;

/** Load `modifiers.recipeItems.rawMaterial.stockUnit` + `modifiers.recipeItems.unit`. */
class ModifierGroupResource extends Resource
{
    use FormatsRecipe;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'rule' => $this->ruleText(),
            'is_active' => $this->is_active,
            'modifiers' => $this->whenLoaded('modifiers', fn () => $this->modifiers->map(fn (Modifier $m) => [
                'id' => $m->uuid,
                'name' => $m->name,
                'price' => $m->price,
                'is_active' => $m->is_active,
                'recipe' => static::recipeOf($m),
                'recipe_cost' => static::recipeCostOf($m),
            ])->values()->all()),
            'menu_items_count' => $this->whenCounted('menuItems'),
            $this->trashFields(),
        ];
    }
}
