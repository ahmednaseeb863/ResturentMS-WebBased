<?php

namespace App\Models\Concerns;

use App\Models\RecipeItem;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Menu items, variants and modifiers: raw materials one serving uses (PLAN §4.7).
 * Recipe lines are synced like pivots — kept lines updated, missing ones trashed,
 * re-added ones restored (one line per raw material).
 */
trait HasRecipe
{
    public function recipeItems(): MorphMany
    {
        return $this->morphMany(RecipeItem::class, 'recipeable')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  list<array{raw_material_id: int, quantity: float, unit_id: int}>  $lines
     * @return list<string> what changed, for the activity log ("+ Chicken 150 g", "− Mayo")
     */
    public function syncRecipe(array $lines): array
    {
        $existing = $this->recipeItems()->withTrashed()->with('rawMaterial', 'unit')->get()->keyBy('raw_material_id');
        $wanted = [];
        $changes = [];

        foreach (array_values($lines) as $i => $line) {
            $id = (int) $line['raw_material_id'];
            $wanted[] = $id;
            $row = $existing->get($id);
            $fields = ['quantity' => $line['quantity'], 'unit_id' => $line['unit_id'], 'sort_order' => $i];

            if (! $row) {
                $row = $this->recipeItems()->create(['raw_material_id' => $id, ...$fields]);
                $changes[] = '+ '.$row->load('rawMaterial', 'unit')->describe();

                continue;
            }

            $restored = $row->isTrashed();
            if ($restored) {
                $row->restoreFromTrash();
            }

            $row->fill($fields);
            $meaningful = $row->isDirty('quantity') || $row->isDirty('unit_id');
            $row->save();

            if ($restored || $meaningful) {
                $changes[] = ($restored ? '+ ' : '~ ').$row->load('unit')->describe();
            }
        }

        foreach ($existing as $id => $row) {
            if (! $row->isTrashed() && ! in_array($id, $wanted, true)) {
                $row->trash();
                $changes[] = '− '.$row->rawMaterial?->name;
            }
        }

        return $changes;
    }
}
