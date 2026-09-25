<?php

namespace App\Http\Resources;

use App\Models\DealSlot;
use App\Models\DealSlotOption;
use App\Models\MenuItem;
use App\Models\ReadyItem;
use App\Support\BusinessDate;
use App\Support\RecipeCost;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

/**
 * A deal with its slots and options, the regular (menu) price of the default picks and
 * their food cost. Load `DealResource::eagerLoads()`.
 */
class DealResource extends Resource
{
    public static function eagerLoads(): array
    {
        $recipe = ['recipeItems.rawMaterial.stockUnit', 'recipeItems.unit'];

        return [
            'slots.options.variant.recipeItems.rawMaterial.stockUnit',
            'slots.options.variant.recipeItems.unit',
            'slots.options.sellable' => fn (MorphTo $morph) => $morph->morphWith([
                MenuItem::class => [...$recipe, 'variants.recipeItems.rawMaterial.stockUnit', 'variants.recipeItems.unit'],
            ]),
        ];
    }

    public function toArray(Request $request): array
    {
        $status = $this->statusOn(BusinessDate::for());
        $slots = $this->relationLoaded('slots') ? $this->slots : collect();

        $defaults = $slots->map(fn (DealSlot $s) => [$s->quantity, $s->options->firstWhere('is_default', true) ?? $s->options->first()]);
        $regular = $defaults->sum(fn ($d) => $d[1] ? $d[0] * $d[1]->regularPrice() : 0);
        $costs = $defaults->map(fn ($d) => $d[1] ? static::costOf($d[1]) : null);

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
            'price' => $this->price,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'days_of_week' => $this->days_of_week ?? [],
            'start_time' => $this->start_time ? substr($this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr($this->end_time, 0, 5) : null,
            'schedule' => $this->scheduleText(),
            'available_for' => $this->available_for,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'status' => ['value' => $status->value, 'label' => $status->label(), 'dot' => $status->dot()],
            'regular_price' => round($regular, 2),
            'cost' => $costs->filter(fn ($c) => $c !== null)->isEmpty()
                ? null
                : round($defaults->keys()->sum(fn ($i) => $defaults[$i][0] * ($costs[$i] ?? 0)), 2),
            'slots' => $slots->map(fn (DealSlot $slot) => [
                'id' => $slot->uuid,
                'name' => $slot->name,
                'quantity' => $slot->quantity,
                'options' => $slot->options->map(fn (DealSlotOption $o) => [
                    'id' => $o->uuid,
                    'type' => $o->sellable_type,
                    'item' => $o->sellable ? ['id' => $o->sellable->uuid, 'name' => $o->sellable->name, 'is_trashed' => $o->sellable->isTrashed()] : null,
                    'variant' => $o->variant ? ['id' => $o->variant->uuid, 'name' => $o->variant->name] : null,
                    'label' => $o->label(),
                    'extra_price' => $o->extra_price,
                    'is_default' => $o->is_default,
                    'regular_price' => $o->regularPrice(),
                ])->values()->all(),
            ])->values()->all(),
            $this->trashFields(),
        ];
    }

    /** Food cost of one pick: the size's recipe → the item's recipe; a ready item's average cost. */
    private static function costOf(DealSlotOption $option): ?float
    {
        $item = $option->sellable;

        if ($item instanceof ReadyItem) {
            return (float) $item->avg_cost ?: null;
        }
        if (! $item instanceof MenuItem) {
            return null;
        }

        $variant = $option->variant ?? ($item->relationLoaded('variants') ? $item->variants->firstWhere('is_default', true) : null);
        foreach ([$variant, $item] as $owner) {
            if ($owner && $owner->relationLoaded('recipeItems') && $owner->recipeItems->isNotEmpty()) {
                return RecipeCost::of($owner->recipeItems);
            }
        }

        return null;
    }
}
