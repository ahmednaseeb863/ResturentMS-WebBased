<?php

namespace App\Http\Resources\Concerns;

use App\Models\RecipeItem;
use App\Support\RecipeCost;
use Illuminate\Database\Eloquent\Model;

/** Recipe lines as the recipe editor reads them (needs `recipeItems.rawMaterial.stockUnit` + `recipeItems.unit`). */
trait FormatsRecipe
{
    protected static function recipeOf(Model $owner): array
    {
        if (! $owner->relationLoaded('recipeItems')) {
            return [];
        }

        return $owner->recipeItems->map(fn (RecipeItem $line) => [
            'raw_material' => $line->rawMaterial ? ['id' => $line->rawMaterial->uuid, 'name' => $line->rawMaterial->name] : null,
            'quantity' => $line->quantity,
            'unit' => $line->unit ? ['id' => $line->unit->uuid, 'short_name' => $line->unit->short_name] : null,
        ])->values()->all();
    }

    protected static function recipeCostOf(Model $owner): ?float
    {
        return $owner->relationLoaded('recipeItems') && $owner->recipeItems->isNotEmpty()
            ? RecipeCost::of($owner->recipeItems)
            : null;
    }
}
