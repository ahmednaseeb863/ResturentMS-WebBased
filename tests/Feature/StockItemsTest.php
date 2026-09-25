<?php

use App\Enums\StockMovementType;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use App\Support\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Units, raw materials, ready items, add stock and the stock ledger (PLAN §2.1, §4.16).
 */

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];
    $this->unit = fn (string $short) => Unit::query()->where('short_name', $short)->sole();
});

function uuidOfUnit(string $short): string
{
    return Unit::query()->where('short_name', $short)->value('uuid');
}

// ── Units ─────────────────────────────────────────────────────────────────

it('converts between units of one family only', function () {
    expect(Unit::convert(1500, ($this->unit)('g'), ($this->unit)('kg')))->toBe(1.5)
        ->and(Unit::convert(2, ($this->unit)('dozen'), ($this->unit)('pcs')))->toBe(24.0)
        ->and(fn () => Unit::convert(1, ($this->unit)('kg'), ($this->unit)('L')))->toThrow(InvalidArgumentException::class);
});

it('adds a unit and refuses to change the conversion of a unit in use', function () {
    loginSuperAdmin();

    $this->post(route('units.store'), ['name' => 'Tola', 'short_name' => 'tola', 'base_unit' => uuidOfUnit('kg'), 'factor' => '0.01166'])
        ->assertSessionHasNoErrors();
    $tola = Unit::query()->where('short_name', 'tola')->sole();
    expect($tola->familyId())->toBe(($this->unit)('kg')->id);

    RawMaterial::factory()->forBranch($this->home)->create(['stock_unit_id' => $tola->id]);

    $this->put(route('units.update', $tola), ['name' => 'Tola', 'short_name' => 'tola', 'base_unit' => uuidOfUnit('kg'), 'factor' => '0.012'])
        ->assertSessionHasErrors('base_unit');
    $this->put(route('units.update', $tola), ['name' => 'Tola (gold)', 'short_name' => 'tola', 'base_unit' => uuidOfUnit('kg'), 'factor' => '0.01166'])
        ->assertSessionHasNoErrors();

    expect(fn () => $tola->trash())->toThrow(TrashNotAllowed::class, 'Used by 1 raw material')
        ->and(fn () => ($this->unit)('kg')->trash())->toThrow(TrashNotAllowed::class, 'Other units are part of');
});

// ── Raw materials ─────────────────────────────────────────────────────────

it('adds a raw material with opening stock through the ledger', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('raw-materials.store'), [
        'name' => 'Chicken fillet',
        'code' => 'chk-01',
        'stock_unit' => uuidOfUnit('kg'),
        'purchase_unit' => uuidOfUnit('g'),
        'alert_level' => 5,
        'opening_stock' => '12.5',
        'unit_cost' => 880,
    ])->assertSessionHasNoErrors();

    $chicken = RawMaterial::query()->forBranch($this->home)->sole();
    expect($chicken)->code->toBe('CHK-01')
        ->current_stock->toBe('12.500')
        ->avg_cost->toBe('880.0000')
        ->purchase_unit_factor->toBe('0.001');

    $line = StockMovement::query()->forBranch($this->home)->sole();
    expect($line)->type->toBe(StockMovementType::Opening)
        ->quantity->toBe('12.500')
        ->balance_after->toBe('12.500')
        ->stockable_type->toBe('raw_material');

    $response = $this->get(route('raw-materials.index'))->assertInertia(fn (Assert $page) => $page
        ->component('raw-materials/Index')
        ->has('materials.data', 1)
        ->where('materials.data.0.stock_unit.short_name', 'kg')
        ->where('materials.data.0.stock_value', 11000)
        ->where('materials.data.0.has_movements', true));

    expectNoNumericIds($response->inertiaProps());
});

it('needs the pack size when the purchase unit is not convertible', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('raw-materials.store'), [
        'name' => 'Burger bun', 'stock_unit' => uuidOfUnit('pcs'), 'purchase_unit' => uuidOfUnit('carton'),
    ])->assertSessionHasErrors('purchase_unit_factor');

    $this->post(route('raw-materials.store'), [
        'name' => 'Burger bun', 'stock_unit' => uuidOfUnit('pcs'), 'purchase_unit' => uuidOfUnit('carton'), 'purchase_unit_factor' => 48,
    ])->assertSessionHasNoErrors();

    expect(RawMaterial::query()->forBranch($this->home)->sole()->purchase_unit_factor)->toBe('48.000');
});

it('keeps codes unique per branch and the stock unit fixed once stock is recorded', function () {
    loginSuperAdmin();
    RawMaterial::factory()->forBranch($this->other)->create(['code' => 'OIL']);
    $oil = RawMaterial::factory()->forBranch($this->home)->unit('L')->create(['name' => 'Oil']);
    app(StockLedger::class)->record($oil, StockMovementType::StockIn, 10, 400);

    $this->withSession($this->atHome)->post(route('raw-materials.store'), ['name' => 'Canola', 'code' => 'oil', 'stock_unit' => uuidOfUnit('L')])
        ->assertSessionHasNoErrors();

    $this->put(route('raw-materials.update', $oil), ['name' => 'Oil', 'stock_unit' => uuidOfUnit('ml')])
        ->assertSessionHasErrors(['stock_unit' => 'Stock is already recorded in L — the stock unit cannot change.']);
});

it('adds stock in the purchase unit and updates the average cost', function () {
    loginSuperAdmin();
    $coke = ReadyItem::factory()->forBranch($this->home)->create([
        'name' => 'Coke 1.5L', 'purchase_unit_id' => ($this->unit)('crate')->id, 'purchase_unit_factor' => 6,
    ]);
    app(StockLedger::class)->record($coke, StockMovementType::Opening, 6, 200);

    $this->withSession($this->atHome)->post(route('stock.add'), [
        'kind' => 'ready_item', 'item' => $coke->uuid, 'quantity' => 2, 'unit' => uuidOfUnit('crate'), 'unit_cost' => 1320, 'note' => 'Weekly delivery',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Added 2 crate (12 pcs) of “Coke 1.5L”. Stock now 18 pcs.');

    // (6 × 200 + 12 × 220) / 18
    expect($coke->fresh())->current_stock->toBe('18.000')->avg_cost->toBe('213.3333');

    $this->post(route('stock.add'), ['kind' => 'ready_item', 'item' => $coke->uuid, 'quantity' => 1, 'unit' => uuidOfUnit('kg')])
        ->assertSessionHasErrors('unit');
});

it('never lets stock go below zero unless the setting allows it', function () {
    $flour = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Flour']);
    $ledger = app(StockLedger::class);
    $ledger->record($flour, StockMovementType::StockIn, 2, 150);

    expect(fn () => $ledger->record($flour, StockMovementType::Consumption, -2.5))
        ->toThrow(ValidationException::class);
    expect($flour->fresh()->current_stock)->toBe('2.000');

    $ledger->record($flour, StockMovementType::Consumption, -1.25);
    expect($flour->fresh()->current_stock)->toBe('0.750')
        ->and(StockMovement::query()->allBranches()->latest('id')->first()->balance_after)->toBe('0.750');
});

it('keeps the ledger append-only', function () {
    $flour = RawMaterial::factory()->forBranch($this->home)->create();
    $line = app(StockLedger::class)->record($flour, StockMovementType::StockIn, 1, 100);

    expect(fn () => $line->update(['quantity' => 5]))->toThrow(PermanentDeleteNotAllowed::class)
        ->and(fn () => $line->delete())->toThrow(PermanentDeleteNotAllowed::class);
});

it('dates stock by the business day (before the cut-off counts for yesterday)', function () {
    $lateNight = CarbonImmutable::parse('2026-09-24 02:30', 'Asia/Karachi');
    $morning = CarbonImmutable::parse('2026-09-24 06:00', 'Asia/Karachi');

    expect(BusinessDate::for($this->home, $lateNight))->toBe('2026-09-23')
        ->and(BusinessDate::for($this->home, $morning))->toBe('2026-09-24');
});

it('shows one item ledger and never another branch', function () {
    loginSuperAdmin();
    $mine = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Cheese']);
    $theirs = RawMaterial::factory()->forBranch($this->other)->create(['name' => 'Cheese elsewhere']);
    app(StockLedger::class)->record($mine, StockMovementType::StockIn, 4, 1800);
    app(StockLedger::class)->record($theirs, StockMovementType::StockIn, 9, 1700);

    $response = $this->withSession($this->atHome)
        ->get(route('stock-ledger.index', ['kind' => 'raw_material', 'item' => $mine->uuid]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('stock-ledger/Index')
            ->has('movements.data', 1)
            ->where('item.name', 'Cheese')
            ->where('movements.data.0.type.label', 'Stock added'));
    expectNoNumericIds($response->inertiaProps());

    $this->get(route('stock-ledger.index'))->assertInertia(fn (Assert $page) => $page->has('movements.data', 1));

    // lines of a trashed item still name it
    $mine->trash();
    $this->get(route('stock-ledger.index'))->assertInertia(fn (Assert $page) => $page->where('movements.data.0.item.name', 'Cheese'));

    $this->put(route('raw-materials.update', $theirs), ['name' => 'Hijack', 'stock_unit' => uuidOfUnit('kg')])->assertNotFound();
    $this->post(route('stock.add'), ['kind' => 'raw_material', 'item' => $theirs->uuid, 'quantity' => 1, 'unit' => uuidOfUnit('kg')])
        ->assertSessionHasErrors('item');
});

it('refuses to trash a raw material a recipe uses, and trashes / restores otherwise', function () {
    loginSuperAdmin();
    $mayo = RawMaterial::factory()->forBranch($this->home)->unit('g')->create(['name' => 'Mayo']);
    $burger = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Zinger']);
    $burger->recipeItems()->create(['raw_material_id' => $mayo->id, 'quantity' => 20, 'unit_id' => ($this->unit)('g')->id]);

    expect(fn () => $mayo->trash())->toThrow(TrashNotAllowed::class, 'Used in the recipe of “Zinger”');

    $burger->trash();
    $this->withSession($this->atHome)->delete(route('raw-materials.destroy', $mayo))->assertSessionHas('success');
    expect($mayo->fresh()->isTrashed())->toBeTrue();

    $this->post(route('raw-materials.restore', $mayo))->assertSessionHas('success');
    expect($mayo->fresh()->isTrashed())->toBeFalse()
        ->and(fn () => $mayo->delete())->toThrow(PermanentDeleteNotAllowed::class);
});

// ── Ready items ───────────────────────────────────────────────────────────

it('adds a ready item with a category of this branch only', function () {
    loginSuperAdmin();
    $drinks = Category::factory()->forBranch($this->home)->create(['name' => 'Drinks']);
    $foreign = Category::factory()->forBranch($this->other)->create();

    $form = [
        'name' => 'Mineral Water', 'price' => 80, 'stock_unit' => uuidOfUnit('pcs'),
        'available_for' => ['dine_in', 'takeaway'], 'opening_stock' => 24, 'unit_cost' => 45,
    ];

    $this->withSession($this->atHome)->post(route('ready-items.store'), [...$form, 'category' => $foreign->uuid])
        ->assertSessionHasErrors('category');
    $this->post(route('ready-items.store'), [...$form, 'category' => $drinks->uuid])->assertSessionHasNoErrors();

    $water = ReadyItem::query()->forBranch($this->home)->sole();
    expect($water)->current_stock->toBe('24.000')->available_for->toBe(['dine_in', 'takeaway']);

    $response = $this->get(route('ready-items.index'))->assertInertia(fn (Assert $page) => $page
        ->component('ready-items/Index')
        ->where('items.data.0.category.name', 'Drinks')
        ->has('orderTypes', 3));
    expectNoNumericIds($response->inertiaProps());
});

it('needs the add stock permission', function () {
    loginAdminWithRoutes(['raw-materials.index'], $this->home);
    $flour = RawMaterial::factory()->forBranch($this->home)->create();

    $this->withSession($this->atHome)->get(route('raw-materials.index'))->assertOk();
    $this->post(route('stock.add'), ['kind' => 'raw_material', 'item' => $flour->uuid, 'quantity' => 1, 'unit' => uuidOfUnit('kg')])
        ->assertForbidden();
});
