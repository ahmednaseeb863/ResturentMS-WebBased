<?php

namespace App\Support;

use App\Models\MenuItem;
use App\Models\OrderItem;
use App\Models\RawMaterial;
use App\Models\RecipeItem;
use App\Models\Unit;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Raw materials an order line should use (PLAN §4.16): the size's own recipe, else the
 * item's, plus the add-ons' recipes — all × the line quantity. Deal picks are their own
 * lines. One entry per raw material (in the recipe's unit; the stock unit when a material
 * appears twice in different units).
 */
class RecipeUse
{
    /** @return Collection<int, array{material: RawMaterial, unit: Unit, quantity: float}> keyed by raw material id */
    public static function of(OrderItem $item): Collection
    {
        $sellable = $item->sellable;
        if (! $sellable instanceof MenuItem) {
            return collect();
        }

        $with = ['rawMaterial.stockUnit', 'rawMaterial.purchaseUnit', 'unit'];
        $variantLines = $item->variant ? $item->variant->recipeItems()->with($with)->get() : collect();
        $lines = $variantLines->isNotEmpty() ? $variantLines : $sellable->recipeItems()->with($with)->get();

        foreach ($item->modifiers()->with('modifier')->get() as $row) {
            if ($row->modifier) {
                $lines = $lines->concat($row->modifier->recipeItems()->with($with)->get());
            }
        }

        return static::merge($lines, $item->quantity);
    }

    /** @param  Collection<int, RecipeItem>  $lines */
    private static function merge(Collection $lines, int $times): Collection
    {
        $use = collect();

        foreach ($lines as $line) {
            $material = $line->rawMaterial;
            if (! $material || $material->isTrashed() || ! $line->unit) {
                continue;
            }

            $quantity = (float) $line->quantity * $times;
            $current = $use->get($material->id);

            if (! $current) {
                $use->put($material->id, ['material' => $material, 'unit' => $line->unit, 'quantity' => $quantity]);

                continue;
            }

            try {
                $current['quantity'] += $current['unit']->is($line->unit)
                    ? $quantity
                    : Unit::convert($quantity, $line->unit, $current['unit']);
            } catch (InvalidArgumentException) {
                // e.g. pcs + carton: add both up in the stock unit
                $current['quantity'] = $material->toStockQuantity($current['quantity'], $current['unit'])
                    + $material->toStockQuantity($quantity, $line->unit);
                $current['unit'] = $material->stockUnit;
            }

            $use->put($material->id, $current);
        }

        return $use->map(fn ($u) => [...$u, 'quantity' => round($u['quantity'], 3)]);
    }
}
