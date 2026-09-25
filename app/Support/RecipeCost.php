<?php

namespace App\Support;

use App\Models\RecipeItem;
use App\Models\Unit;
use InvalidArgumentException;

/**
 * Food cost of a recipe at the raw materials' average cost (an estimate for the menu
 * screens; real consumption is costed when the kitchen confirms it).
 * Needs `rawMaterial.stockUnit` and `unit` loaded on the lines.
 */
class RecipeCost
{
    /** @param  iterable<RecipeItem>  $lines */
    public static function of(iterable $lines): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $material = $line->rawMaterial;

            if (! $material || ! $line->unit || ! $material->stockUnit) {
                continue;
            }

            try {
                $total += Unit::convert((float) $line->quantity, $line->unit, $material->stockUnit) * (float) $material->avg_cost;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return round($total, 2);
    }
}
