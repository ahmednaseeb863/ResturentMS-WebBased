<?php

use App\Enums\OrderType;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\DealSlotOption;
use App\Models\Discount;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Models\Unit;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Deals with slots of menu items / ready items, their availability rules, and
 * predefined discounts (PLAN §4.8).
 */

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];

    $this->zinger = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Zinger', 'price' => 650]);
    $this->regular = $this->zinger->variants()->create(['name' => 'Regular', 'price' => 650, 'is_default' => true]);
    $this->large = $this->zinger->variants()->create(['name' => 'Large', 'price' => 900, 'sort_order' => 1]);
    $this->coke = ReadyItem::factory()->forBranch($this->home)->create(['name' => 'Coke', 'price' => 250]);
    $this->sprite = ReadyItem::factory()->forBranch($this->home)->create(['name' => 'Sprite', 'price' => 250]);
});

function dealForm(array $overrides = []): array
{
    return [
        'name' => 'Zinger Meal',
        'price' => 799,
        'available_for' => ['dine_in', 'takeaway'],
        'slots' => [
            ['name' => 'Burger', 'quantity' => 1, 'options' => [
                ['type' => 'menu_item', 'item' => test()->zinger->uuid, 'variant' => test()->regular->uuid],
            ]],
            ['name' => 'Drink', 'quantity' => 2, 'options' => [
                ['type' => 'ready_item', 'item' => test()->coke->uuid],
                ['type' => 'ready_item', 'item' => test()->sprite->uuid, 'extra_price' => 20, 'is_default' => true],
            ]],
        ],
        ...$overrides,
    ];
}

it('adds a deal with fixed items and a choice', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('deals.store'), dealForm())->assertSessionHasNoErrors();

    $deal = Deal::query()->forBranch($this->home)->with('slots.options')->sole();
    expect($deal->slots->pluck('name')->all())->toBe(['Burger', 'Drink'])
        ->and($deal->slots[1]->quantity)->toBe(2)
        ->and($deal->slots[1]->options->pluck('is_default')->all())->toBe([false, true])
        ->and($deal->slots[0]->options[0]->variant_id)->toBe($this->regular->id)
        ->and($deal->days_of_week)->toBeNull();

    $response = $this->get(route('deals.index'))->assertInertia(fn (Assert $page) => $page
        ->component('deals/Index')
        ->where('deals.data.0.regular_price', 1150)            // 650 + 2 × 250 (default Sprite)
        ->where('deals.data.0.slots.0.options.0.label', 'Zinger — Regular')
        ->where('deals.data.0.status.value', 'active')
        ->where('statusCounts.active', 1)
        ->has('sellables', 3));

    expectNoNumericIds($response->inertiaProps());
});

it('refuses items of another branch, sizes of another item and duplicates in a slot', function () {
    loginSuperAdmin();
    $foreign = ReadyItem::factory()->forBranch($this->other)->create();
    $otherItem = MenuItem::factory()->forBranch($this->home)->create();
    $otherSize = $otherItem->variants()->create(['name' => 'Small', 'price' => 100]);

    $this->withSession($this->atHome)->post(route('deals.store'), dealForm(['slots' => [
        ['name' => 'Burger', 'quantity' => 1, 'options' => [
            ['type' => 'menu_item', 'item' => $this->zinger->uuid, 'variant' => $otherSize->uuid],
        ]],
        ['name' => 'Drink', 'quantity' => 1, 'options' => [
            ['type' => 'ready_item', 'item' => $foreign->uuid],
            ['type' => 'ready_item', 'item' => $this->coke->uuid],
            ['type' => 'ready_item', 'item' => $this->coke->uuid],
        ]],
    ]]))->assertSessionHasErrors([
        'slots.0.options.0.variant' => 'Pick a size of “Zinger”.',
        'slots.1.options.0.item' => 'Pick a menu item or ready item of this branch.',
        'slots.1.options.2.item' => '“Coke” is already in this slot.',
    ]);

    $this->post(route('deals.store'), dealForm(['slots' => [], 'ends_on' => '2026-01-01', 'starts_on' => '2026-02-01', 'start_time' => '12:00']))
        ->assertSessionHasErrors(['slots', 'ends_on', 'end_time']);

    expect(Deal::query()->allBranches()->count())->toBe(0);
});

it('edits a deal: kept slots and options are updated, removed ones trashed, and the change is logged', function () {
    loginSuperAdmin();
    $this->withSession($this->atHome)->post(route('deals.store'), dealForm());
    $deal = Deal::query()->forBranch($this->home)->with('slots.options')->sole();
    [$burger, $drink] = $deal->slots;

    // drop Sprite, change the burger to Large, add a dessert slot
    $this->put(route('deals.update', $deal), dealForm(['slots' => [
        ['id' => $burger->uuid, 'name' => 'Burger', 'quantity' => 1, 'options' => [
            ['id' => $burger->options[0]->uuid, 'type' => 'menu_item', 'item' => $this->zinger->uuid, 'variant' => $this->large->uuid],
        ]],
        ['id' => $drink->uuid, 'name' => 'Drink', 'quantity' => 1, 'options' => [
            ['id' => $drink->options[0]->uuid, 'type' => 'ready_item', 'item' => $this->coke->uuid],
        ]],
    ]]))->assertSessionHasNoErrors();

    expect($burger->options()->sole()->variant_id)->toBe($this->large->id)
        ->and($drink->options()->count())->toBe(1)
        ->and($drink->options()->onlyTrashed()->count())->toBe(1)
        ->and($drink->options()->sole()->is_default)->toBeTrue(); // the only option becomes the default

    $log = ActivityLog::where('event', 'deal_setup')->sole();
    expect($log->properties['before'])->toBe(['Burger: Zinger — Regular', 'Drink ×2: Coke or Sprite (+20)'])
        ->and($log->properties['after'])->toBe(['Burger: Zinger — Large', 'Drink: Coke']);

    // a slot id from another deal is refused
    $other = Deal::factory()->forBranch($this->home)->create();
    $foreignSlot = $other->slots()->create(['name' => 'X', 'quantity' => 1]);
    $this->put(route('deals.update', $deal), dealForm(['slots' => [
        ['id' => $foreignSlot->uuid, 'name' => 'X', 'quantity' => 1, 'options' => [['type' => 'ready_item', 'item' => $this->coke->uuid]]],
    ]]))->assertSessionHasErrors('slots.0.name');
});

it('keeps items that are part of a deal out of the trash', function () {
    loginSuperAdmin();
    $this->withSession($this->atHome)->post(route('deals.store'), dealForm());

    expect(fn () => $this->coke->trash())->toThrow(TrashNotAllowed::class, 'Part of the deal “Zinger Meal”')
        ->and(fn () => $this->zinger->trash())->toThrow(TrashNotAllowed::class, 'Part of the deal “Zinger Meal”');

    // removing the fixed size from the menu item is refused too
    $this->put(route('menu-items.update', $this->zinger), [
        'name' => 'Zinger', 'category' => $this->zinger->category->uuid, 'available_for' => ['dine_in'],
        'variants' => [['id' => $this->large->uuid, 'name' => 'Large', 'price' => 900]],
    ])->assertSessionHasErrors(['variants' => 'Size “Regular” — part of the deal “Zinger Meal” — remove it there first.']);
    expect($this->regular->fresh()->isTrashed())->toBeFalse();
});

it('trashes a deal with its slots, and restores it only while its items exist', function () {
    loginSuperAdmin();
    $this->withSession($this->atHome)->post(route('deals.store'), dealForm());
    $deal = Deal::query()->forBranch($this->home)->sole();

    $this->delete(route('deals.destroy', $deal))->assertSessionHas('success');
    expect($deal->slots()->count())->toBe(0)
        ->and(DealSlotOption::query()->count())->toBe(0)
        ->and(fn () => $deal->delete())->toThrow(PermanentDeleteNotAllowed::class);

    // with the deal gone, Sprite can be trashed — then the deal can't come back
    $this->sprite->trash();
    expect(fn () => $deal->fresh()->restoreFromTrash())->toThrow(TrashNotAllowed::class, '“Sprite” is in the trash');

    $this->sprite->restoreFromTrash();
    $this->post(route('deals.restore', $deal))->assertSessionHas('success');
    expect($deal->slots()->count())->toBe(2)->and(DealSlotOption::query()->count())->toBe(3);
});

it('sells a deal only inside its dates, days, time window and order types', function () {
    // default settings: Asia/Karachi, business day starts 05:00
    $deal = Deal::factory()->forBranch($this->home)->create([
        'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31',
        'days_of_week' => [5, 6],                 // Fri, Sat
        'start_time' => '22:00', 'end_time' => '02:00',
        'available_for' => [OrderType::Delivery->value],
    ]);
    $at = fn (string $local) => CarbonImmutable::parse($local, 'Asia/Karachi');

    expect($deal->scheduleText())->toBe('Fri, Sat · 22:00–02:00')
        ->and($deal->isAvailableAt($at('2026-10-02 23:00')))->toBeTrue()       // Friday night
        ->and($deal->isAvailableAt($at('2026-10-03 01:30')))->toBeTrue()       // still Friday's business day
        ->and($deal->isAvailableAt($at('2026-10-02 21:00')))->toBeFalse()      // too early
        ->and($deal->isAvailableAt($at('2026-10-01 23:00')))->toBeFalse()      // Thursday
        ->and($deal->isAvailableAt($at('2026-11-06 23:00')))->toBeFalse()      // after the end date
        ->and($deal->isAvailableAt($at('2026-10-02 23:00'), OrderType::DineIn))->toBeFalse()
        ->and($deal->isAvailableAt($at('2026-10-02 23:00'), OrderType::Delivery))->toBeTrue();

    $deal->update(['is_active' => false]);
    expect($deal->isAvailableAt($at('2026-10-02 23:00')))->toBeFalse();
});

it('cannot open a deal of another branch', function () {
    loginSuperAdmin();
    $deal = Deal::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)->put(route('deals.update', $deal), dealForm())->assertNotFound();
});

// ── Discounts ─────────────────────────────────────────────────────────────

it('adds discounts and works out the amount', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('discounts.store'), [
        'name' => 'Too much', 'type' => 'percent', 'value' => 120, 'applies_to' => 'order',
    ])->assertSessionHasErrors(['value' => 'A percent discount cannot be more than 100%.']);

    $this->post(route('discounts.store'), [
        'name' => 'Flat', 'type' => 'fixed', 'value' => 100, 'applies_to' => 'order', 'max_amount' => 50,
    ])->assertSessionHasErrors(['max_amount' => 'Only a percent discount can have a maximum.']);

    $this->post(route('discounts.store'), [
        'name' => 'Student', 'type' => 'percent', 'value' => 10, 'applies_to' => 'order',
        'max_amount' => 300, 'min_order_amount' => 1000, 'requires_approval' => true,
    ])->assertSessionHasNoErrors();

    $student = Discount::query()->forBranch($this->home)->sole();
    expect($student->amountOn(999))->toBe(0.0)         // below the minimum
        ->and($student->amountOn(2000))->toBe(200.0)
        ->and($student->amountOn(5000))->toBe(300.0)   // capped
        ->and($student->valueText())->toBe('10%');

    $this->post(route('discounts.store'), ['name' => 'Student', 'type' => 'fixed', 'value' => 5, 'applies_to' => 'item'])
        ->assertSessionHasErrors('name');

    $response = $this->get(route('discounts.index'))->assertInertia(fn (Assert $page) => $page
        ->component('discounts/Index')
        ->where('discounts.data.0.requires_approval', true)
        ->where('discounts.data.0.status.value', 'active'));
    expectNoNumericIds($response->inertiaProps());
});

it('filters discounts by status and keeps other branches out', function () {
    loginSuperAdmin();
    $today = CarbonImmutable::parse(BusinessDate::for($this->home));
    Discount::factory()->forBranch($this->home)->create(['name' => 'Running']);
    Discount::factory()->forBranch($this->home)->create(['name' => 'Soon', 'starts_on' => $today->addWeek()]);
    Discount::factory()->forBranch($this->home)->create(['name' => 'Gone', 'ends_on' => $today->subDay()]);
    Discount::factory()->forBranch($this->home)->create(['name' => 'Off', 'is_active' => false]);
    $foreign = Discount::factory()->forBranch($this->other)->create(['name' => 'Elsewhere']);

    $this->withSession($this->atHome)->get(route('discounts.index', ['status' => 'scheduled']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('discounts.data', 1)
            ->where('discounts.data.0.name', 'Soon')
            ->where('statusCounts', ['active' => 1, 'scheduled' => 1, 'expired' => 1, 'inactive' => 1]));

    $this->put(route('discounts.update', $foreign), ['name' => 'X', 'type' => 'fixed', 'value' => 1, 'applies_to' => 'order'])
        ->assertNotFound();

    $discount = Discount::query()->forBranch($this->home)->where('name', 'Off')->sole();
    $this->delete(route('discounts.destroy', $discount))->assertSessionHas('success');
    $this->post(route('discounts.restore', $discount))->assertSessionHas('success');
    expect($discount->fresh()->isTrashed())->toBeFalse();
});

it('lets a manager with deal routes only see deals', function () {
    loginAdminWithRoutes(['deals.index'], $this->home);

    $this->withSession($this->atHome)->get(route('deals.index'))->assertOk();
    $this->post(route('deals.store'), dealForm())->assertForbidden();
    $this->get(route('discounts.index'))->assertForbidden();
});

// ── Copy menu ─────────────────────────────────────────────────────────────

it('copies deals and discounts with the menu', function () {
    loginSuperAdmin();
    $this->withSession($this->atHome)->post(route('deals.store'), dealForm());
    Discount::factory()->forBranch($this->home)->create(['name' => 'Staff']);

    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])
        ->post(route('menu-items.copy'), ['from' => $this->home->uuid])
        ->assertSessionHasNoErrors();

    $deal = Deal::query()->forBranch($this->other)->with('slots.options.sellable', 'slots.options.variant')->sole();
    $burger = $deal->slots[0]->options[0];
    expect($burger->sellable->branch_id)->toBe($this->other->id)
        ->and($burger->variant->name)->toBe('Regular')
        ->and($burger->variant->menu_item_id)->toBe($burger->sellable_id)
        ->and($deal->slots[1]->options->map(fn ($o) => $o->sellable->name)->all())->toBe(['Coke', 'Sprite'])
        ->and(Discount::query()->forBranch($this->other)->pluck('name')->all())->toBe(['Staff'])
        ->and(MenuItemVariant::query()->count())->toBe(4)
        ->and(RawMaterial::query()->allBranches()->count())->toBe(0)
        ->and(Unit::query()->count())->toBeGreaterThan(0);

    // twice → nothing new
    $this->post(route('menu-items.copy'), ['from' => $this->home->uuid]);
    expect(Deal::query()->forBranch($this->other)->count())->toBe(1);
});
