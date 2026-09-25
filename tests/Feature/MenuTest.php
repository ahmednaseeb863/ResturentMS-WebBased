<?php

use App\Enums\StockMovementType;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\MenuItemModifierGroup;
use App\Models\ModifierGroup;
use App\Models\Printer;
use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Support\CurrentBranch;
use App\Support\StockLedger;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Kitchen stations, categories, menu items with recipes / sizes / add-ons, add-on
 * groups and copying a menu between branches (PLAN §4.7).
 */

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];

    $unit = fn (string $s) => Unit::query()->where('short_name', $s)->sole();
    $this->g = $unit('g');
    $this->kg = $unit('kg');
    $this->pcs = $unit('pcs');

    $this->burgers = Category::factory()->forBranch($this->home)->create(['name' => 'Burgers']);
    $this->chicken = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Chicken', 'avg_cost' => 900]);
    $this->bun = RawMaterial::factory()->forBranch($this->home)->unit('pcs')->create(['name' => 'Bun', 'avg_cost' => 30]);
    $this->cheese = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Cheese', 'avg_cost' => 2000]);
});

function line(RawMaterial $material, float $qty, Unit $unit): array
{
    return ['raw_material' => $material->uuid, 'quantity' => $qty, 'unit' => $unit->uuid];
}

function menuForm(array $overrides = []): array
{
    return [
        'name' => 'Zinger Burger',
        'category' => test()->burgers->uuid,
        'price' => 650,
        'available_for' => ['dine_in', 'takeaway', 'delivery'],
        'recipe' => [line(test()->bun, 1, test()->pcs), line(test()->chicken, 150, test()->g)],
        ...$overrides,
    ];
}

// ── Menu items ────────────────────────────────────────────────────────────

it('adds a menu item with its recipe, sizes and add-ons', function () {
    loginSuperAdmin();
    $extras = ModifierGroup::factory()->forBranch($this->home)->create(['name' => 'Extras']);

    $this->withSession($this->atHome)->post(route('menu-items.store'), menuForm([
        'variants' => [
            ['name' => 'Regular', 'price' => 650],
            ['name' => 'Double', 'price' => 900, 'is_default' => true, 'recipe' => [line($this->bun, 1, $this->pcs), line($this->chicken, 0.3, $this->kg)]],
        ],
        'modifier_groups' => [$extras->uuid],
    ]))->assertSessionHasNoErrors();

    $item = MenuItem::query()->forBranch($this->home)->with('variants.recipeItems', 'recipeItems', 'modifierGroups')->sole();
    expect($item)->price->toBe('900.00') // the default size's price
        ->slug->toBe('zinger-burger')
        ->and($item->recipeItems)->toHaveCount(2)
        ->and($item->variants->pluck('name')->all())->toBe(['Regular', 'Double'])
        ->and($item->variants[1]->recipeItems)->toHaveCount(2)
        ->and($item->modifierGroups->pluck('name')->all())->toBe(['Extras']);

    $response = $this->get(route('menu-items.index'))->assertInertia(fn (Assert $page) => $page
        ->component('menu-items/Index')
        ->where('items.data.0.recipe.1.raw_material.name', 'Chicken')
        ->where('items.data.0.recipe_cost', 165)          // 30 + 0.15 kg × 900
        ->where('items.data.0.variants.0.recipe_cost', 165) // no own recipe → item's
        ->where('items.data.0.variants.1.recipe_cost', 300) // 30 + 0.3 × 900
        ->has('rawMaterials', 3)
        ->has('copyBranches', 1));

    expectNoNumericIds($response->inertiaProps());
});

it('checks recipe units and refuses raw materials of another branch', function () {
    loginSuperAdmin();
    $foreign = RawMaterial::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)->post(route('menu-items.store'), menuForm([
        'recipe' => [line($this->chicken, 150, $this->pcs), line($foreign, 1, $this->kg), line($this->bun, 1, $this->pcs), line($this->bun, 2, $this->pcs)],
    ]))->assertSessionHasErrors([
        'recipe.0.unit' => 'Chicken is counted in kg — pick a matching unit.',
        'recipe.1.raw_material' => 'Pick a raw material of this branch.',
        'recipe.3.raw_material' => '“Bun” is already in this recipe.',
    ]);

    expect(MenuItem::query()->allBranches()->count())->toBe(0);
});

it('trashes removed sizes and recipe lines, and restores a line that comes back', function () {
    loginSuperAdmin();
    $this->withSession($this->atHome)->post(route('menu-items.store'), menuForm([
        'variants' => [['name' => 'Regular', 'price' => 650, 'is_default' => true], ['name' => 'Large', 'price' => 850]],
    ]));
    $item = MenuItem::query()->forBranch($this->home)->with('variants')->sole();
    $regular = $item->variants[0];

    // drop "Large" and the chicken line
    $this->put(route('menu-items.update', $item), menuForm([
        'recipe' => [line($this->bun, 1, $this->pcs)],
        'variants' => [['id' => $regular->uuid, 'name' => 'Regular', 'price' => 700, 'is_default' => true]],
    ]))->assertSessionHasNoErrors();

    expect($item->variants()->count())->toBe(1)
        ->and($item->variants()->onlyTrashed()->count())->toBe(1)
        ->and($item->recipeItems()->count())->toBe(1)
        ->and($item->fresh()->price)->toBe('700.00');

    $log = ActivityLog::where('event', 'menu_setup')->latest('id')->firstOrFail();
    expect($log->properties['sizes removed'])->toBe(['Large'])
        ->and($log->properties['recipe'])->toBe(['− Chicken']);

    // chicken comes back → the same row is restored, not duplicated
    $this->put(route('menu-items.update', $item), menuForm(['variants' => [['id' => $regular->uuid, 'name' => 'Regular', 'price' => 700]]]))
        ->assertSessionHasNoErrors();
    expect(RecipeItem::withTrashed()->where('raw_material_id', $this->chicken->id)->count())->toBe(1)
        ->and($item->recipeItems()->count())->toBe(2);
});

it('trashes a menu item with its sizes and restores them together', function () {
    loginSuperAdmin();
    $item = MenuItem::factory()->forBranch($this->home)->create(['category_id' => $this->burgers->id]);
    $item->variants()->create(['name' => 'Regular', 'price' => 650, 'is_default' => true]);

    $this->withSession($this->atHome)->delete(route('menu-items.destroy', $item))->assertSessionHas('success');
    expect($item->fresh()->isTrashed())->toBeTrue()->and($item->variants()->count())->toBe(0);

    $this->post(route('menu-items.restore', $item))->assertSessionHas('success');
    expect($item->variants()->count())->toBe(1)
        ->and(fn () => $item->delete())->toThrow(PermanentDeleteNotAllowed::class);
});

it('cannot open a menu item of another branch', function () {
    loginSuperAdmin();
    $item = MenuItem::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)->put(route('menu-items.update', $item), menuForm())->assertNotFound();
});

// ── Add-on groups ─────────────────────────────────────────────────────────

it('saves an add-on group with options and their raw materials', function () {
    loginSuperAdmin();

    $form = [
        'name' => 'Extra toppings', 'min_select' => 0, 'max_select' => 2,
        'modifiers' => [
            ['name' => 'Extra cheese', 'price' => 100, 'recipe' => [line($this->cheese, 30, $this->g)]],
            ['name' => 'Jalapeños', 'price' => 50],
        ],
    ];

    $this->withSession($this->atHome)->post(route('modifier-groups.store'), [...$form, 'min_select' => 3])
        ->assertSessionHasErrors('min_select');
    $this->post(route('modifier-groups.store'), $form)->assertSessionHasNoErrors();

    $group = ModifierGroup::query()->forBranch($this->home)->with('modifiers.recipeItems')->sole();
    expect($group->ruleText())->toBe('Optional · up to 2')
        ->and($group->modifiers[0]->recipeItems[0]->quantity)->toBe('30.000');

    // remove Jalapeños
    $this->put(route('modifier-groups.update', $group), [...$form, 'modifiers' => [
        ['id' => $group->modifiers[0]->uuid, 'name' => 'Extra cheese', 'price' => 120, 'recipe' => [line($this->cheese, 30, $this->g)]],
    ]])->assertSessionHasNoErrors();

    expect($group->modifiers()->count())->toBe(1)->and($group->modifiers()->onlyTrashed()->count())->toBe(1);

    $response = $this->get(route('modifier-groups.index'))->assertInertia(fn (Assert $page) => $page
        ->where('groups.data.0.modifiers.0.recipe_cost', 60));
    expectNoNumericIds($response->inertiaProps());
});

// ── Stations & categories ─────────────────────────────────────────────────

it('links a kitchen station to a kitchen printer and guards what is in use', function () {
    loginSuperAdmin();
    $kot = Printer::factory()->forBranch($this->home)->kitchen()->create(['name' => 'Grill KOT']);
    $receipt = Printer::factory()->forBranch($this->home)->create();

    $this->withSession($this->atHome)->post(route('kitchen-stations.store'), ['name' => 'Grill', 'printer' => $receipt->uuid])
        ->assertSessionHasErrors('printer');
    $this->post(route('kitchen-stations.store'), ['name' => 'Grill', 'printer' => $kot->uuid, 'has_screen' => true])
        ->assertSessionHasNoErrors();

    $grill = KitchenStation::query()->forBranch($this->home)->sole();
    $this->burgers->update(['kitchen_station_id' => $grill->id]);

    expect(fn () => $kot->trash())->toThrow(TrashNotAllowed::class, 'Used by “Grill”')
        ->and(fn () => $grill->trash())->toThrow(TrashNotAllowed::class, 'Used by 1 categories')
        ->and(fn () => $this->burgers->fresh()->trash())->not->toThrow(TrashNotAllowed::class);

    MenuItem::factory()->forBranch($this->home)->create(['category_id' => $this->burgers->id]);
    $this->burgers->fresh()->restoreFromTrash();
    expect(fn () => $this->burgers->fresh()->trash())->toThrow(TrashNotAllowed::class, 'It still has 1 menu items');

    $response = $this->get(route('kitchen-stations.index'))->assertInertia(fn (Assert $page) => $page
        ->where('stations.data.0.printer.name', 'Grill KOT')
        ->where('stations.data.0.categories_count', 1));
    expectNoNumericIds($response->inertiaProps());
});

it('lists categories with their item counts', function () {
    loginSuperAdmin();
    MenuItem::factory()->forBranch($this->home)->count(2)->create(['category_id' => $this->burgers->id]);
    Category::factory()->forBranch($this->other)->create(['name' => 'Elsewhere']);

    $this->withSession($this->atHome)->post(route('categories.store'), ['name' => 'Burgers'])->assertSessionHasErrors('name');

    $response = $this->get(route('categories.index'))->assertInertia(fn (Assert $page) => $page
        ->component('categories/Index')
        ->has('categories.data', 1)
        ->where('categories.data.0.menu_items_count', 2));
    expectNoNumericIds($response->inertiaProps());
});

// ── Copy menu ─────────────────────────────────────────────────────────────

it('copies a menu into the current branch without stock, and skips what is already there', function () {
    loginSuperAdmin();
    $grill = KitchenStation::factory()->forBranch($this->home)->create(['name' => 'Grill', 'printer_id' => Printer::factory()->forBranch($this->home)->kitchen()->create()->id]);
    $this->burgers->update(['kitchen_station_id' => $grill->id]);
    app(StockLedger::class)->record($this->chicken, StockMovementType::StockIn, 20, 900);

    $extras = ModifierGroup::factory()->forBranch($this->home)->create(['name' => 'Extras']);
    $cheese = $extras->modifiers()->create(['name' => 'Cheese', 'price' => 100]);
    $cheese->recipeItems()->create(['raw_material_id' => $this->cheese->id, 'quantity' => 30, 'unit_id' => $this->g->id]);

    $this->withSession($this->atHome)->post(route('menu-items.store'), menuForm([
        'variants' => [['name' => 'Regular', 'price' => 650], ['name' => 'Double', 'price' => 900, 'recipe' => [line($this->chicken, 300, $this->g)]]],
        'modifier_groups' => [$extras->uuid],
    ]))->assertSessionHasNoErrors();
    ReadyItem::factory()->forBranch($this->home)->create(['name' => 'Coke', 'category_id' => $this->burgers->id, 'code' => 'CK']);

    // DHA already has a "Chicken" (reused) and a "Zinger Burger" is not there yet
    $dhaChicken = RawMaterial::factory()->forBranch($this->other)->create(['name' => 'Chicken']);
    $atDha = [CurrentBranch::SESSION_KEY => $this->other->id];

    $this->withSession($atDha)->post(route('menu-items.copy'), ['from' => $this->home->uuid])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $copy = MenuItem::query()->forBranch($this->other)->with('recipeItems', 'variants.recipeItems', 'modifierGroups.modifiers.recipeItems', 'category.kitchenStation')->sole();
    expect($copy->category->name)->toBe('Burgers')
        ->and($copy->category->branch_id)->toBe($this->other->id)
        ->and($copy->category->kitchenStation->name)->toBe('Grill')
        ->and($copy->category->kitchenStation->printer_id)->toBeNull()
        ->and($copy->recipeItems->pluck('raw_material_id'))->toContain($dhaChicken->id)
        ->and($copy->variants[1]->recipeItems[0]->raw_material_id)->toBe($dhaChicken->id)
        ->and($copy->modifierGroups->pluck('name')->all())->toBe(['Extras'])
        ->and($copy->modifierGroups[0]->branch_id)->toBe($this->other->id)
        ->and($copy->modifierGroups[0]->modifiers[0]->recipeItems)->toHaveCount(1)
        ->and(RawMaterial::query()->forBranch($this->other)->count())->toBe(3)
        ->and(RawMaterial::query()->forBranch($this->other)->sum('current_stock'))->toEqual(0)
        ->and(ReadyItem::query()->forBranch($this->other)->sole())->current_stock->toBe('0.000')->code->toBe('CK');

    // second run: nothing new
    $this->withSession($atDha)->post(route('menu-items.copy'), ['from' => $this->home->uuid])
        ->assertSessionHas('success', fn (string $m) => str_starts_with($m, 'Nothing new to copy'));
    expect(MenuItem::query()->forBranch($this->other)->count())->toBe(1)
        ->and(MenuItemModifierGroup::query()->count())->toBe(2);
});

it('copies only from a branch the admin can access, with the copy permission', function () {
    $third = Branch::factory()->create();
    loginAdminWithRoutes(['menu-items.index', 'menu-items.copy'], $this->home, $this->other);

    $this->withSession($this->atHome)->post(route('menu-items.copy'), ['from' => $third->uuid])->assertSessionHasErrors('from');
    $this->post(route('menu-items.copy'), ['from' => $this->home->uuid])->assertSessionHasErrors('from');
    $this->post(route('menu-items.copy'), ['from' => $this->other->uuid])->assertSessionHasNoErrors();

    loginAdminWithRoutes(['menu-items.index'], $this->home, $this->other);
    $this->withSession($this->atHome)->post(route('menu-items.copy'), ['from' => $this->other->uuid])->assertForbidden();
    $this->get(route('menu-items.index'))->assertInertia(fn (Assert $page) => $page->where('copyBranches', []));
});
