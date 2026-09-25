<?php

namespace Database\Seeders;

use App\Actions\SaveStockItem;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\MenuItemModifierGroup;
use App\Models\ModifierGroup;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\ReadyItem;
use App\Models\Unit;
use App\Support\CurrentBranch;
use App\Support\TrashablePivot;
use Illuminate\Database\Seeder;

/**
 * A small sample menu for a branch (local development only): stations, categories,
 * raw materials with opening stock, a ready item, an add-on group and a burger with
 * its recipe and sizes. Skipped when the branch already has menu items.
 */
class DemoMenuSeeder extends Seeder
{
    public function run(?Branch $branch = null): void
    {
        $branch ??= Branch::query()->where('code', 'MAIN')->firstOrFail();

        app(CurrentBranch::class)->actingAs($branch, function () {
            if (MenuItem::query()->withTrashed()->exists()) {
                return;
            }

            $unit = fn (string $short) => Unit::query()->where('short_name', $short)->value('id');
            $save = app(SaveStockItem::class);

            $grill = KitchenStation::create(['name' => 'Grill', 'has_screen' => true]);
            $bar = KitchenStation::create(['name' => 'Drinks', 'has_screen' => false]);

            $burgers = Category::create(['name' => 'Burgers', 'kitchen_station_id' => $grill->id, 'sort_order' => 1]);
            $drinks = Category::create(['name' => 'Drinks', 'kitchen_station_id' => $bar->id, 'sort_order' => 9]);

            $meat = RawMaterialCategory::create(['name' => 'Meat']);
            $bakery = RawMaterialCategory::create(['name' => 'Bakery']);
            $dairy = RawMaterialCategory::create(['name' => 'Dairy & Sauces']);

            $material = fn (array $data, float $opening, float $cost) => $save->handle(new RawMaterial, $data, ['quantity' => $opening, 'unit_cost' => $cost]);

            $chicken = $material(['name' => 'Chicken fillet', 'code' => 'CHK', 'category_id' => $meat->id, 'stock_unit_id' => $unit('kg'), 'alert_level' => 5], 10, 900);
            $bun = $material(['name' => 'Burger bun', 'code' => 'BUN', 'category_id' => $bakery->id, 'stock_unit_id' => $unit('pcs'), 'purchase_unit_id' => $unit('carton'), 'purchase_unit_factor' => 48, 'alert_level' => 24], 96, 25);
            $mayo = $material(['name' => 'Mayonnaise', 'code' => 'MAYO', 'category_id' => $dairy->id, 'stock_unit_id' => $unit('kg'), 'alert_level' => 1], 2, 600);
            $cheese = $material(['name' => 'Cheese slice', 'code' => 'CHS', 'category_id' => $dairy->id, 'stock_unit_id' => $unit('pcs'), 'alert_level' => 20], 100, 20);

            $save->handle(new ReadyItem, [
                'name' => 'Coke 1.5L', 'code' => 'COKE15', 'category_id' => $drinks->id, 'price' => 250,
                'stock_unit_id' => $unit('pcs'), 'purchase_unit_id' => $unit('crate'), 'purchase_unit_factor' => 6,
                'alert_level' => 6, 'available_for' => OrderType::values(),
            ], ['quantity' => 24, 'unit_cost' => 200]);

            $extras = ModifierGroup::create(['name' => 'Extras', 'min_select' => 0, 'max_select' => 3]);
            $extraCheese = $extras->modifiers()->create(['name' => 'Extra cheese', 'price' => 80]);
            $extraCheese->syncRecipe([['raw_material_id' => $cheese->id, 'quantity' => 1, 'unit_id' => $unit('pcs')]]);
            $extras->modifiers()->create(['name' => 'Jalapeños', 'price' => 50, 'sort_order' => 1]);

            $zinger = MenuItem::create([
                'name' => 'Zinger Burger', 'category_id' => $burgers->id, 'price' => 650, 'prep_time_minutes' => 12,
                'description' => 'Crispy chicken fillet, lettuce and mayo', 'available_for' => OrderType::values(),
            ]);
            $zinger->syncRecipe([
                ['raw_material_id' => $bun->id, 'quantity' => 1, 'unit_id' => $unit('pcs')],
                ['raw_material_id' => $chicken->id, 'quantity' => 150, 'unit_id' => $unit('g')],
                ['raw_material_id' => $mayo->id, 'quantity' => 20, 'unit_id' => $unit('g')],
            ]);
            $zinger->variants()->create(['name' => 'Regular', 'price' => 650, 'is_default' => true]);
            $zinger->variants()->create(['name' => 'Double', 'price' => 950, 'sort_order' => 1])->syncRecipe([
                ['raw_material_id' => $bun->id, 'quantity' => 1, 'unit_id' => $unit('pcs')],
                ['raw_material_id' => $chicken->id, 'quantity' => 300, 'unit_id' => $unit('g')],
                ['raw_material_id' => $mayo->id, 'quantity' => 30, 'unit_id' => $unit('g')],
            ]);
            TrashablePivot::sync(MenuItemModifierGroup::class, 'menu_item_id', $zinger->id, 'modifier_group_id', [$extras->id]);
        });
    }
}
