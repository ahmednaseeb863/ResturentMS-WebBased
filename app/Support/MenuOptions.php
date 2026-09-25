<?php

namespace App\Support;

use App\Enums\PrinterType;
use App\Models\Area;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Printer;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\ReadyItem;
use App\Models\Unit;

/**
 * Picker options for the menu and stock screens (current branch, uuids only).
 * Units carry `family` (uuid of the base unit) + `factor` so the recipe editor and the
 * add-stock dialog can offer matching units and estimate costs.
 */
class MenuOptions
{
    public static function units(): array
    {
        $units = Unit::query()->with('baseUnit')->orderBy('base_unit_id')->orderBy('name')->get();

        return $units->map(fn (Unit $u) => [
            'value' => $u->uuid,
            'label' => $u->short_name,
            'name' => $u->name,
            'family' => $u->baseUnit?->uuid ?? $u->uuid,
            'factor' => $u->factor,
        ])->values()->all();
    }

    public static function stations(): array
    {
        return KitchenStation::query()->active()->orderBy('name')->get()
            ->map(fn (KitchenStation $s) => ['value' => $s->uuid, 'label' => $s->name])->all();
    }

    public static function categories(): array
    {
        return Category::query()->with('kitchenStation')->ordered()->get()
            ->map(fn (Category $c) => [
                'value' => $c->uuid,
                'label' => $c->name.($c->is_active ? '' : ' (inactive)'),
                'station' => $c->kitchenStation?->name,
            ])->all();
    }

    public static function rawMaterialCategories(): array
    {
        return RawMaterialCategory::query()->orderBy('name')->get()
            ->map(fn (RawMaterialCategory $c) => ['value' => $c->uuid, 'label' => $c->name])->all();
    }

    /** Raw materials for the recipe editor, with the stock unit's family and cost per stock unit. */
    public static function rawMaterials(): array
    {
        return RawMaterial::query()->with('stockUnit.baseUnit')->orderBy('name')->get()
            ->map(fn (RawMaterial $m) => [
                'value' => $m->uuid,
                'label' => $m->name.($m->is_active ? '' : ' (inactive)'),
                'unit' => $m->stockUnit?->uuid,
                'family' => $m->stockUnit?->baseUnit?->uuid ?? $m->stockUnit?->uuid,
                'cost' => (float) $m->avg_cost,
            ])->all();
    }

    public static function modifierGroups(): array
    {
        return ModifierGroup::query()->orderBy('name')->get()
            ->map(fn (ModifierGroup $g) => [
                'value' => $g->uuid,
                'label' => $g->name.($g->is_active ? '' : ' (inactive)'),
                'rule' => $g->ruleText(),
            ])->all();
    }

    /**
     * Menu items (with their sizes) and ready items for the deal builder. `value` is
     * "type:uuid"; the deal form splits it into `type` + `item`.
     */
    public static function sellables(): array
    {
        $menu = MenuItem::query()->with('variants', 'category')->orderBy('name')->get()
            ->map(fn (MenuItem $m) => [
                'value' => "menu_item:{$m->uuid}",
                'label' => $m->name.($m->is_active ? '' : ' (hidden)'),
                'group' => $m->category?->name,
                'kind' => 'Menu item',
                'price' => (float) $m->price,
                'variants' => $m->variants->map(fn ($v) => ['value' => $v->uuid, 'label' => $v->name, 'price' => (float) $v->price])->all(),
            ]);

        $ready = ReadyItem::query()->with('category')->orderBy('name')->get()
            ->map(fn (ReadyItem $r) => [
                'value' => "ready_item:{$r->uuid}",
                'label' => $r->name.($r->is_active ? '' : ' (hidden)'),
                'group' => $r->category?->name,
                'kind' => 'Ready item',
                'price' => (float) $r->price,
                'variants' => [],
            ]);

        return $menu->concat($ready)->values()->all();
    }

    public static function areas(): array
    {
        return Area::query()->ordered()->get()
            ->map(fn (Area $a) => ['value' => $a->uuid, 'label' => $a->name.($a->is_active ? '' : ' (inactive)')])->all();
    }

    public static function kitchenPrinters(): array
    {
        return Printer::query()->active()->ofType(PrinterType::Kitchen)->orderBy('name')->get()
            ->map(fn (Printer $p) => ['value' => $p->uuid, 'label' => $p->name])->all();
    }
}
