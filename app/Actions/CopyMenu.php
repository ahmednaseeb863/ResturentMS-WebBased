<?php

namespace App\Actions;

use App\Exceptions\TrashNotAllowed;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Deal;
use App\Models\DealSlot;
use App\Models\DealSlotOption;
use App\Models\Discount;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\MenuItemModifierGroup;
use App\Models\MenuItemVariant;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\ReadyItem;
use App\Models\RecipeItem;
use App\Support\Activity;
use App\Support\CurrentBranch;
use App\Support\TrashablePivot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Copies a branch's menu into another branch (PLAN §2): kitchen stations, categories,
 * raw materials, ready items, add-on groups, menu items with their variants and
 * recipes, deals and discounts — **without stock**. Things the target already has (same
 * name) are reused, not duplicated; menu / ready items / deals that already exist there
 * are skipped. Printers are not copied (they are the target branch's own hardware).
 */
class CopyMenu
{
    /** source id => target id */
    private array $stations = [];

    private array $categories = [];

    private array $materialCategories = [];

    private array $materials = [];

    private array $groups = [];

    /** source id => target id, including items the target already had */
    private array $menuItems = [];

    private array $readyItems = [];

    private array $counts = [];

    /** @return array<string, int> created / reused / skipped counts */
    public function handle(Branch $from, Branch $to): array
    {
        $this->counts = array_fill_keys([
            'stations', 'categories', 'raw materials', 'add-on groups', 'menu items', 'ready items', 'deals', 'discounts', 'reused', 'skipped',
        ], 0);

        app(CurrentBranch::class)->actingAs($to, fn () => DB::transaction(function () use ($from, $to) {
            $this->copyStations($from);
            $this->copyCategories($from);
            $this->copyRawMaterials($from);
            $this->copyModifierGroups($from);
            $this->copyMenuItems($from);
            $this->copyReadyItems($from);
            $this->copyDeals($from);
            $this->copyDiscounts($from);

            Activity::log('menu_copied', $to, ['from' => $from->name, ...array_filter($this->counts)]);
        }));

        return $this->counts;
    }

    private function copyStations(Branch $from): void
    {
        foreach (KitchenStation::query()->forBranch($from)->get() as $source) {
            $this->stations[$source->id] = $this->matchOrCreate(KitchenStation::class, $source->name, 'stations', fn () => KitchenStation::create([
                'name' => $source->name,
                'has_screen' => $source->has_screen,
                'is_active' => $source->is_active,
            ]))?->id;
        }
    }

    private function copyCategories(Branch $from): void
    {
        foreach (Category::query()->forBranch($from)->get() as $source) {
            $this->categories[$source->id] = $this->matchOrCreate(Category::class, $source->name, 'categories', fn () => Category::create([
                'name' => $source->name,
                'image' => $source->image,
                'kitchen_station_id' => $this->stations[$source->kitchen_station_id] ?? null,
                'sort_order' => $source->sort_order,
                'is_active' => $source->is_active,
            ]))?->id;
        }
    }

    private function copyRawMaterials(Branch $from): void
    {
        foreach (RawMaterialCategory::query()->forBranch($from)->get() as $source) {
            $this->materialCategories[$source->id] = $this->matchOrCreate(RawMaterialCategory::class, $source->name, null, fn () => RawMaterialCategory::create(['name' => $source->name]))?->id;
        }

        foreach (RawMaterial::query()->forBranch($from)->get() as $source) {
            $this->materials[$source->id] = $this->matchOrCreate(RawMaterial::class, $source->name, 'raw materials', function () use ($source) {
                $material = RawMaterial::create([
                    'name' => $source->name,
                    'code' => $this->freeValue(RawMaterial::class, 'code', $source->code),
                    'category_id' => $this->materialCategories[$source->category_id] ?? null,
                    'stock_unit_id' => $source->stock_unit_id,
                    'purchase_unit_id' => $source->purchase_unit_id,
                    'purchase_unit_factor' => $source->purchase_unit_factor,
                    'alert_level' => $source->alert_level,
                    'is_active' => $source->is_active,
                ]);
                $material->forceFill(['avg_cost' => $source->avg_cost])->saveQuietly(); // cost for recipe costing, no stock

                return $material;
            })?->id;
        }
    }

    private function copyModifierGroups(Branch $from): void
    {
        $sources = ModifierGroup::query()->forBranch($from)->with('modifiers.recipeItems')->get();

        foreach ($sources as $source) {
            $this->groups[$source->id] = $this->matchOrCreate(ModifierGroup::class, $source->name, 'add-on groups', function () use ($source) {
                $group = ModifierGroup::create($source->only(['name', 'min_select', 'max_select', 'is_active']));

                foreach ($source->modifiers as $sourceModifier) {
                    $modifier = Modifier::create([
                        ...$sourceModifier->only(['name', 'price', 'is_active', 'sort_order']),
                        'modifier_group_id' => $group->id,
                    ]);
                    $this->copyRecipe($sourceModifier, $modifier);
                }

                return $group;
            })?->id;
        }
    }

    private function copyMenuItems(Branch $from): void
    {
        $sources = MenuItem::query()->forBranch($from)
            // (we act as the target branch here — the source's groups sit outside its scope)
            ->with(['recipeItems', 'variants.recipeItems', 'modifierGroups' => fn ($q) => $q->withoutGlobalScope('branch')])
            ->get();

        foreach ($sources as $source) {
            $categoryId = $this->categories[$source->category_id] ?? null;

            $existing = MenuItem::query()->withTrashed()->where('name', $source->name)->first();

            if (! $categoryId || $existing) {
                if ($existing && ! $existing->isTrashed()) {
                    $this->menuItems[$source->id] = $existing->id;
                }
                $this->counts['skipped']++;

                continue;
            }

            $item = MenuItem::create([
                ...$source->only(['name', 'description', 'image', 'price', 'prep_time_minutes', 'available_for', 'is_active', 'sort_order']),
                'category_id' => $categoryId,
                'kitchen_station_id' => $this->stations[$source->kitchen_station_id] ?? null,
            ]);
            $this->copyRecipe($source, $item);
            $this->menuItems[$source->id] = $item->id;

            foreach ($source->variants as $sourceVariant) {
                $variant = MenuItemVariant::create([
                    ...$sourceVariant->only(['name', 'price', 'is_default', 'sort_order']),
                    'menu_item_id' => $item->id,
                ]);
                $this->copyRecipe($sourceVariant, $variant);
            }

            $groupIds = $source->modifierGroups->map(fn (ModifierGroup $g) => $this->groups[$g->id] ?? null)->filter()->values()->all();
            TrashablePivot::sync(MenuItemModifierGroup::class, 'menu_item_id', $item->id, 'modifier_group_id', $groupIds);
            foreach ($groupIds as $i => $groupId) {
                MenuItemModifierGroup::query()->where('menu_item_id', $item->id)->where('modifier_group_id', $groupId)->update(['sort_order' => $i]);
            }

            $this->counts['menu items']++;
        }
    }

    private function copyReadyItems(Branch $from): void
    {
        foreach (ReadyItem::query()->forBranch($from)->get() as $source) {
            $categoryId = $this->categories[$source->category_id] ?? null;

            $existing = ReadyItem::query()->withTrashed()->where('name', $source->name)->first();

            if (! $categoryId || $existing) {
                if ($existing && ! $existing->isTrashed()) {
                    $this->readyItems[$source->id] = $existing->id;
                }
                $this->counts['skipped']++;

                continue;
            }

            $item = ReadyItem::create([
                ...$source->only(['name', 'image', 'price', 'stock_unit_id', 'purchase_unit_id', 'purchase_unit_factor', 'alert_level', 'available_for', 'is_active', 'sort_order']),
                'code' => $this->freeValue(ReadyItem::class, 'code', $source->code),
                'barcode' => $this->freeValue(ReadyItem::class, 'barcode', $source->barcode),
                'category_id' => $categoryId,
                'kitchen_station_id' => $this->stations[$source->kitchen_station_id] ?? null,
            ]);
            $item->forceFill(['avg_cost' => $source->avg_cost])->saveQuietly();
            $this->readyItems[$source->id] = $item->id;

            $this->counts['ready items']++;
        }
    }

    /** Deals whose items made it to the target; picks of missing items (or sizes) are left out. */
    private function copyDeals(Branch $from): void
    {
        $sources = Deal::query()->forBranch($from)->with('slots.options.variant')->get();

        foreach ($sources as $source) {
            if (Deal::query()->withTrashed()->where('name', $source->name)->exists()) {
                $this->counts['skipped']++;

                continue;
            }

            $slots = $source->slots->map(fn (DealSlot $slot) => [
                'slot' => $slot,
                'options' => $slot->options->map(fn (DealSlotOption $o) => $this->mapOption($o))->filter()->values(),
            ])->filter(fn ($s) => $s['options']->isNotEmpty())->values();

            if ($slots->count() !== $source->slots->count()) {
                $this->counts['skipped']++; // a whole slot has nothing to offer here

                continue;
            }

            $deal = Deal::create($source->only([
                'name', 'description', 'image', 'price', 'starts_on', 'ends_on', 'days_of_week',
                'start_time', 'end_time', 'available_for', 'is_active', 'sort_order',
            ]));

            foreach ($slots as $i => ['slot' => $sourceSlot, 'options' => $options]) {
                $slot = DealSlot::create([...$sourceSlot->only(['name', 'quantity']), 'sort_order' => $i, 'deal_id' => $deal->id]);
                $default = $options->search(fn ($o) => $o['is_default']);

                foreach ($options as $j => $option) {
                    DealSlotOption::create([...$option, 'is_default' => $j === ($default === false ? 0 : $default), 'sort_order' => $j, 'deal_slot_id' => $slot->id]);
                }
            }

            $this->counts['deals']++;
        }
    }

    /** The target's item (and size, by name) for a source pick, or null. */
    private function mapOption(DealSlotOption $option): ?array
    {
        $isMenu = $option->sellable_type === 'menu_item';
        $targetId = $isMenu ? ($this->menuItems[$option->sellable_id] ?? null) : ($this->readyItems[$option->sellable_id] ?? null);

        if (! $targetId) {
            return null;
        }

        $variantId = null;
        if ($isMenu && $option->variant) {
            $variantId = MenuItemVariant::query()->where('menu_item_id', $targetId)->where('name', $option->variant->name)->value('id');
            if (! $variantId) {
                return null;
            }
        }

        return [
            'sellable_type' => $option->sellable_type,
            'sellable_id' => $targetId,
            'variant_id' => $variantId,
            'extra_price' => $option->extra_price,
            'is_default' => $option->is_default,
        ];
    }

    private function copyDiscounts(Branch $from): void
    {
        foreach (Discount::query()->forBranch($from)->get() as $source) {
            $this->matchOrCreate(Discount::class, $source->name, 'discounts', fn () => Discount::create($source->only([
                'name', 'type', 'value', 'applies_to', 'max_amount', 'min_order_amount',
                'starts_on', 'ends_on', 'requires_approval', 'is_active',
            ])));
        }
    }

    /** Recipe lines whose raw material made it to the target branch. */
    private function copyRecipe(Model $source, Model $target): void
    {
        $source->recipeItems->each(function (RecipeItem $line) use ($target) {
            $materialId = $this->materials[$line->raw_material_id] ?? null;

            if ($materialId) {
                $target->recipeItems()->create([
                    'raw_material_id' => $materialId,
                    'quantity' => $line->quantity,
                    'unit_id' => $line->unit_id,
                    'sort_order' => $line->sort_order,
                ]);
            }
        });
    }

    /**
     * The target's record with this name (restored if it was in the trash), or a new one.
     *
     * @param  class-string<Model>  $model
     */
    private function matchOrCreate(string $model, string $name, ?string $counter, callable $create): ?Model
    {
        $existing = $model::query()->withTrashed()->where('name', $name)->first();

        if (! $existing) {
            $created = $create();
            if ($counter) {
                $this->counts[$counter]++;
            }

            return $created;
        }

        if ($existing->isTrashed()) {
            try {
                $existing->restoreFromTrash();
            } catch (TrashNotAllowed) {
                return null;
            }
        }

        if ($counter) {
            $this->counts['reused']++;
        }

        return $existing;
    }

    /** Keep a code / barcode only when the target branch does not use it yet. */
    private function freeValue(string $model, string $column, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $model::query()->withTrashed()->where($column, $value)->exists() ? null : $value;
    }
}
