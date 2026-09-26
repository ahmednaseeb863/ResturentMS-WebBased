<?php

use App\Actions\MarkKitchenReady;
use App\Enums\CashMovementType;
use App\Enums\DeliveryStatus;
use App\Enums\DesignationType;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryZone;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReadyItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Support\CurrentBranch;
use App\Support\LiveUpdates;
use App\Support\Settings\SettingsResolver;
use App\Support\ShiftSummary;
use App\Support\StockLedger;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Riders & delivery (PLAN §4.14): delivery zones (fee + minimum), riders given at the POS or
 * on the board, rider panel (picked up / delivered / failed), COD cash held by the rider
 * until settled into a shift, returns, and rider cash blocking the shift close.
 */

const DLV_CASHIER_ROUTES = [
    'pos.index', 'pos.orders.store', 'pos.orders.update', 'orders.index', 'orders.show', 'orders.cancel', 'orders.payments.store',
    'deliveries.index', 'deliveries.assign', 'deliveries.status', 'riders.index', 'riders.settle', 'shifts.show', 'shifts.close',
];
const DLV_RIDER_ROUTES = ['rider.index', 'rider.deliveries.status'];

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];

    $grill = KitchenStation::factory()->forBranch($this->home)->create(['name' => 'Grill']);
    $burgers = Category::factory()->forBranch($this->home)->create(['name' => 'Burgers', 'kitchen_station_id' => $grill->id]);
    $drinks = Category::factory()->forBranch($this->home)->create(['name' => 'Drinks']);
    $this->zinger = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Zinger', 'price' => 600, 'category_id' => $burgers->id]);
    $this->coke = ReadyItem::factory()->forBranch($this->home)->create(['name' => 'Coke', 'price' => 150, 'category_id' => $drinks->id]);
    app(StockLedger::class)->record($this->coke, StockMovementType::Opening, 50);

    $this->counter = CashCounter::factory()->forBranch($this->home)->create(['name' => 'Counter 1']);
    $this->customer = Customer::factory()->withAddress(['address' => 'House 5, Street 2', 'area' => 'Gulberg III'])->create(['name' => 'Ali', 'phone' => '03001234567']);
    $this->riderDesignation = Designation::factory()->type(DesignationType::Rider)->create(['name' => 'Rider']);

    dlvSetting('tax', 'enabled', false);
    dlvSetting('delivery', 'default_fee', 100);
    dlvSetting('approvals', 'pin_void', false);
    dlvSetting('shifts', 'require_denominations', false);
});

function dlvSetting(string $group, string $key, mixed $value): void
{
    $branch = test()->home;
    Setting::query()->updateOrCreate(['branch_id' => $branch->id, 'group' => $group, 'key' => $key], ['value' => $value]);
    app(SettingsResolver::class)->forget($branch->id);
}

/** A cashier signed in, with an open shift (opening cash 5,000) on Counter 1. */
function dlvCashier(array $routes = DLV_CASHIER_ROUTES): Admin
{
    $test = test();
    $admin = loginAdminWithRoutes($routes, $test->home);
    $test->shift = Shift::factory()->create(['branch_id' => $test->home->id, 'cash_counter_id' => $test->counter->id, 'opened_by' => $admin->id]);
    $test->cashier = $admin;

    return $admin;
}

/** An active rider of the home branch with a rider-panel login (not signed in). */
function dlvRider(string $name = 'Asif', ?Branch $branch = null): Employee
{
    $branch ??= test()->home;
    $role = Role::factory()->withRoutes(...DLV_RIDER_ROUTES)->create();
    $admin = Admin::factory()->withRole($role)->forBranches($branch)->create(['name' => $name]);

    return Employee::factory()->forBranch($branch)->create([
        'name' => $name,
        'admin_id' => $admin->id,
        'designation_id' => test()->riderDesignation->id,
    ]);
}

/** A delivery order sent from the POS for Ali's home address. */
function dlvOrder(array $items, array $extra = []): Order
{
    test()->withSession(test()->atHome)->post(route('pos.orders.store'), [
        'action' => 'send',
        'type' => 'delivery',
        'customer' => test()->customer->uuid,
        'address' => test()->customer->addresses()->first()->uuid,
        'items' => $items,
        ...$extra,
    ])->assertSessionHasNoErrors();

    return Order::query()->with('delivery')->latest('id')->firstOrFail();
}

function dlvLine(MenuItem|ReadyItem $item, int $quantity = 1): array
{
    return ['type' => $item instanceof ReadyItem ? 'ready_item' : 'menu_item', 'id' => $item->uuid, 'quantity' => $quantity];
}

function dlvCook(Order $order): void
{
    $order->tickets()->whereIn('status', [KitchenStatus::Pending, KitchenStatus::Preparing])->get()
        ->each(fn (KitchenTicket $t) => app(MarkKitchenReady::class)->handle($t, null, [], Admin::factory()->superAdmin()->create()));
}

function dlvAs(Admin $admin)
{
    return test()->actingAs($admin, 'admin')->withSession(test()->atHome);
}

// ── Zones ────────────────────────────────────────────────────────────────

it('manages delivery zones: add, edit, trash, restore, no numeric ids, other branch hidden', function () {
    loginAdminWithRoutes(['delivery-zones.index', 'delivery-zones.store', 'delivery-zones.update', 'delivery-zones.destroy', 'delivery-zones.restore'], $this->home);
    DeliveryZone::factory()->forBranch($this->other)->create(['name' => 'Theirs']);

    $this->withSession($this->atHome)->post(route('delivery-zones.store'), ['name' => 'Gulberg', 'fee' => 150, 'min_order_amount' => 800])
        ->assertSessionHasNoErrors();
    $zone = DeliveryZone::query()->sole();
    expect($zone)->branch_id->toBe($this->home->id)->fee->toBe('150.00')->min_order_amount->toBe('800.00');

    $this->post(route('delivery-zones.store'), ['name' => 'Gulberg', 'fee' => 100])->assertSessionHasErrors('name');
    $this->put(route('delivery-zones.update', $zone), ['name' => 'Gulberg', 'fee' => 200, 'min_order_amount' => null])->assertSessionHasNoErrors();
    expect($zone->fresh())->fee->toBe('200.00')->min_order_amount->toBeNull();

    $response = $this->get(route('delivery-zones.index'))->assertInertia(fn (Assert $page) => $page
        ->component('delivery-zones/Index')
        ->has('zones.data', 1)
        ->where('zones.data.0.id', $zone->uuid));
    expectNoNumericIds($response->inertiaProps());

    $this->delete(route('delivery-zones.destroy', $zone), ['reason' => 'Merged'])->assertSessionHasNoErrors();
    expect($zone->fresh()->isTrashed())->toBeTrue();
    $this->post(route('delivery-zones.restore', $zone))->assertSessionHasNoErrors();
    expect($zone->fresh()->isTrashed())->toBeFalse();
    expect(fn () => $zone->delete())->toThrow(LogicException::class);

    $theirs = DeliveryZone::query()->allBranches()->where('name', 'Theirs')->sole();
    $this->put(route('delivery-zones.update', $theirs), ['name' => 'X', 'fee' => 1])->assertNotFound();
});

it('takes the zone fee and minimum at the POS when zones are on', function () {
    dlvCashier();
    dlvSetting('delivery', 'use_zones', true);
    $near = DeliveryZone::factory()->forBranch($this->home)->create(['name' => 'Near', 'fee' => 50]);
    $far = DeliveryZone::factory()->forBranch($this->home)->create(['name' => 'Far', 'fee' => 250, 'min_order_amount' => 1000]);

    $send = fn (array $extra) => $this->withSession($this->atHome)->post(route('pos.orders.store'), [
        'action' => 'send', 'type' => 'delivery', 'customer' => $this->customer->uuid,
        'address' => $this->customer->addresses()->first()->uuid, 'items' => [dlvLine($this->zinger)], ...$extra,
    ]);

    $send([])->assertSessionHasErrors(['zone' => 'Pick the delivery zone.']);
    $send(['zone' => $far->uuid])->assertSessionHasErrors(['order' => 'Delivery orders must be at least Rs 1,000 — this one is Rs 600.']);
    $send(['zone' => $near->uuid])->assertSessionHasNoErrors();

    $order = Order::query()->with('delivery')->sole();
    expect($order)->delivery_fee->toBe('50.00')->grand_total->toBe('650.00');
    expect($order->delivery->delivery_zone_id)->toBe($near->id);

    // a changed zone changes the fee of the placed order
    $this->put(route('pos.orders.update', $order), ['action' => 'save', 'customer' => $this->customer->uuid,
        'address' => $this->customer->addresses()->first()->uuid, 'zone' => $far->uuid])->assertSessionHasNoErrors();
    expect($order->fresh())->delivery_fee->toBe('250.00')->grand_total->toBe('850.00');
});

// ── Riders ───────────────────────────────────────────────────────────────

it('gives a delivery to a rider at the POS and on the board, and tells the rider', function () {
    dlvCashier();
    $asif = dlvRider('Asif');
    $bilal = dlvRider('Bilal');
    $cook = Employee::factory()->forBranch($this->home)->create(['name' => 'Cook']);

    $order = dlvOrder([dlvLine($this->coke)], ['rider' => $asif->uuid]);
    expect($order->delivery)->rider_id->toBe($asif->id)->status->toBe(DeliveryStatus::Assigned)->assigned_by->toBe($this->cashier->id);

    $events = LiveUpdates::poll($this->home->id, ['deliveries'], 0)['events']['deliveries'];
    expect(collect($events)->last())->kind->toBe('assigned')->rider->toBe($asif->uuid)->code->toBe($order->code());

    $this->put(route('deliveries.assign', $order->delivery), ['rider' => $cook->uuid])->assertSessionHasErrors(['rider' => 'Pick an active rider of this branch.']);
    $this->put(route('deliveries.assign', $order->delivery), ['rider' => $bilal->uuid])
        ->assertSessionHasNoErrors()->assertSessionHas('success', "{$order->code()} given to Bilal.");
    expect($order->delivery->fresh()->rider_id)->toBe($bilal->id);

    $this->put(route('deliveries.assign', $order->delivery), ['rider' => null])->assertSessionHasNoErrors();
    expect($order->delivery->fresh())->rider_id->toBeNull()->status->toBe(DeliveryStatus::Pending);

    $response = $this->get(route('deliveries.index'))->assertInertia(fn (Assert $page) => $page
        ->component('deliveries/Index')
        ->has('deliveries', 1)
        ->where('deliveries.0.order.code', $order->code())
        ->where('counts.pending', 1)
        ->has('riders', 2));
    expectNoNumericIds($response->inertiaProps());
});

it('refuses giving riders without the permission', function () {
    dlvCashier(array_values(array_diff(DLV_CASHIER_ROUTES, ['deliveries.assign'])));
    $asif = dlvRider();

    $this->withSession($this->atHome)->post(route('pos.orders.store'), [
        'action' => 'send', 'type' => 'delivery', 'customer' => $this->customer->uuid,
        'address' => $this->customer->addresses()->first()->uuid, 'items' => [dlvLine($this->coke)], 'rider' => $asif->uuid,
    ])->assertSessionHasErrors(['rider' => 'You may not give deliveries to riders.']);

    $this->withSession($this->atHome)->post(route('pos.orders.store'), [
        'action' => 'send', 'type' => 'delivery', 'customer' => $this->customer->uuid,
        'address' => $this->customer->addresses()->first()->uuid, 'items' => [dlvLine($this->coke)],
    ])->assertSessionHasNoErrors();
    $delivery = Delivery::query()->sole();
    $this->put(route('deliveries.assign', $delivery), ['rider' => $asif->uuid])->assertForbidden();
});

// ── The trip and the cash ────────────────────────────────────────────────

it('runs a COD delivery: pick up after the kitchen, deliver with cash held by the rider, settle into a shift', function () {
    dlvCashier();
    $asif = dlvRider('Asif');
    $order = dlvOrder([dlvLine($this->zinger), dlvLine($this->coke)], ['rider' => $asif->uuid]); // 600 + 150 + 100 fee
    $delivery = $order->delivery;
    $rider = $asif->admin;

    // the rider sees it; still cooking — can't leave yet
    dlvAs($rider)->get(route('rider.index'))->assertInertia(fn (Assert $page) => $page
        ->component('rider/Index')
        ->where('me.id', $asif->uuid)
        ->has('deliveries', 1)
        ->where('deliveries.0.order.cooking', true)
        ->where('deliveries.0.order.due', 850));
    dlvAs($rider)->put(route('rider.deliveries.status', $delivery), ['action' => 'out'])
        ->assertSessionHasErrors(['delivery' => "{$order->code()} is still being prepared."]);

    dlvCook($order);
    expect($order->fresh()->status)->toBe(OrderStatus::Ready); // not completed — nothing paid, not delivered

    dlvAs($rider)->put(route('rider.deliveries.status', $delivery), ['action' => 'out'])->assertSessionHasNoErrors();
    expect($delivery->fresh())->status->toBe(DeliveryStatus::OutForDelivery)->cash_to_collect->toBe('850.00')->picked_up_at->not->toBeNull();
    expect($order->fresh()->status)->toBe(OrderStatus::OutForDelivery);

    // the order can't change while the food is out
    dlvAs($this->cashier)->put(route('pos.orders.update', $order), ['action' => 'send', 'items' => [dlvLine($this->coke)]])
        ->assertSessionHasErrors(['order' => "Order {$order->code()} is Out for delivery — it can't be changed."]);

    dlvAs($rider)->put(route('rider.deliveries.status', $delivery), ['action' => 'deliver'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', "{$order->code()} delivered — Rs 850 cash with Asif.");

    $payment = Payment::query()->sole();
    expect($payment)->method->toBe(PaymentMethod::Cash)->amount->toBe('850.00')->shift_id->toBeNull()
        ->collected_by_rider_id->toBe($asif->id)->received_by->toBe($rider->id);
    expect($order->fresh())->status->toBe(OrderStatus::Completed)->paid_total->toBe('850.00');
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Delivered)->cash_collected->toBe('850.00')->settled_at->toBeNull();
    expect(Delivery::cashHeld($this->home->id))->toBe(850.0);

    // not in any drawer yet
    expect(ShiftSummary::of($this->shift)->expectedCash())->toBe(5000.0);

    dlvAs($rider)->get(route('rider.index', ['view' => 'cash']))->assertInertia(fn (Assert $page) => $page
        ->has('unsettled', 1)->has('done', 1)->where('view', 'cash'));

    // the cashier settles it into their shift
    $response = dlvAs($this->cashier)->get(route('riders.index'))->assertInertia(fn (Assert $page) => $page
        ->component('riders/Index')
        ->where('cashHeld', 850)
        ->where('riders.0.cash_held', 850)
        ->has('unsettled', 1));
    expectNoNumericIds($response->inertiaProps());

    $this->post(route('riders.settle', $asif))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', "Rs 850 from Asif added to shift {$this->shift->code()}.");

    $movement = CashMovement::query()->sole();
    expect($movement)->type->toBe(CashMovementType::RiderSettlement)->amount->toBe('850.00')->rider_id->toBe($asif->id)->shift_id->toBe($this->shift->id);
    expect($delivery->fresh())->settled_at->not->toBeNull()->settlement_movement_id->toBe($movement->id);
    expect(ShiftSummary::of($this->shift)->expectedCash())->toBe(5850.0);
    expect(Delivery::cashHeld($this->home->id))->toBe(0.0);

    $this->post(route('riders.settle', $asif))->assertSessionHasErrors(['rider' => 'Asif holds no cash to settle.']);
});

it('keeps a prepaid delivery open until it is delivered, and collects nothing', function () {
    dlvCashier();
    $asif = dlvRider();
    $order = dlvOrder([dlvLine($this->coke, 2)], ['rider' => $asif->uuid]); // ready items only: 300 + 100

    $this->post(route('orders.payments.store', $order), ['tenders' => [['method' => 'cash', 'amount' => 400, 'tendered' => 400]]])->assertSessionHasNoErrors();
    expect($order->fresh()->status)->not->toBe(OrderStatus::Completed);

    $this->put(route('deliveries.status', $order->delivery), ['action' => 'out'])->assertSessionHasNoErrors();
    $this->put(route('deliveries.status', $order->delivery), ['action' => 'deliver'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', "{$order->code()} delivered.");

    expect(Payment::query()->count())->toBe(1);
    expect($order->delivery->fresh())->cash_collected->toBe('0.00')->holdsCash()->toBeFalse();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('handles a failed delivery: reason, returned to the shop, sent again or cancelled', function () {
    dlvCashier();
    $asif = dlvRider('Asif');
    $bilal = dlvRider('Bilal');
    $order = dlvOrder([dlvLine($this->coke)], ['rider' => $asif->uuid]);
    $delivery = $order->delivery;

    dlvAs($asif->admin)->put(route('rider.deliveries.status', $delivery), ['action' => 'out'])->assertSessionHasNoErrors();
    dlvAs($asif->admin)->put(route('rider.deliveries.status', $delivery), ['action' => 'fail'])
        ->assertSessionHasErrors(['reason' => 'Say why it could not be delivered.']);
    dlvAs($asif->admin)->put(route('rider.deliveries.status', $delivery), ['action' => 'fail', 'reason' => 'Customer not answering'])
        ->assertSessionHasNoErrors();
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Failed)->failed_reason->toBe('Customer not answering');

    // the rider still has the food: no cancel, no new rider
    dlvAs($this->cashier)->put(route('orders.cancel', $order), ['reason' => 'No answer'])
        ->assertSessionHasErrors(['reason' => "{$order->code()} is Failed — mark it returned first."]);
    $this->put(route('deliveries.assign', $delivery), ['rider' => $bilal->uuid])
        ->assertSessionHasErrors(['rider' => "{$order->code()} is Failed — the rider can't be changed now."]);

    $this->put(route('deliveries.status', $delivery), ['action' => 'return'])->assertSessionHasNoErrors();
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Returned);
    expect($order->fresh()->status)->toBe(OrderStatus::Ready);

    // a new attempt with another rider
    $this->put(route('deliveries.assign', $delivery), ['rider' => $bilal->uuid])->assertSessionHasNoErrors();
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Assigned)->rider_id->toBe($bilal->id)->failed_reason->toBeNull();

    $this->put(route('orders.cancel', $order), ['reason' => 'Customer cancelled'])->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Cancelled);
});

it('lets riders move only their own deliveries of their branch', function () {
    dlvCashier();
    $asif = dlvRider('Asif');
    $bilal = dlvRider('Bilal');
    $order = dlvOrder([dlvLine($this->coke)], ['rider' => $asif->uuid]);

    dlvAs($bilal->admin)->get(route('rider.index'))->assertInertia(fn (Assert $page) => $page->has('deliveries', 0));
    dlvAs($bilal->admin)->put(route('rider.deliveries.status', $order->delivery), ['action' => 'out'])->assertForbidden();
    dlvAs($asif->admin)->put(route('rider.deliveries.status', $order->delivery), ['action' => 'return'])->assertSessionHasErrors('action');

    // a delivery of another branch is not found
    $response = dlvAs($asif->admin)->get(route('rider.index'))->assertInertia(fn (Assert $page) => $page->has('deliveries', 1));
    expectNoNumericIds($response->inertiaProps());
    $theirs = Delivery::query()->withoutGlobalScope('branch')->sole()->replicate(['uuid']);
    $theirs->fill(['branch_id' => $this->other->id, 'order_id' => Order::factory()->create(['branch_id' => $this->other->id])->id])->save();
    dlvAs($asif->admin)->put(route('rider.deliveries.status', $theirs), ['action' => 'out'])->assertNotFound();

    expect($asif->admin->homeRoute())->toBe('rider.index');
});

// ── Shift close ──────────────────────────────────────────────────────────

it('blocks closing a shift while riders hold cash, unless a manager carries it over', function () {
    dlvCashier();
    $asif = dlvRider();
    $order = dlvOrder([dlvLine($this->coke)], ['rider' => $asif->uuid]); // 250
    $this->put(route('deliveries.status', $order->delivery), ['action' => 'out']);
    $this->put(route('deliveries.status', $order->delivery), ['action' => 'deliver'])->assertSessionHasNoErrors();

    $this->get(route('shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page->where('riderCash', 250)->where('isShiftManager', false));

    $close = fn (array $extra = []) => $this->withSession($this->atHome)->put(route('shifts.close', $this->shift), ['counted_cash' => 5000, 'float_left' => 0, ...$extra]);

    $close()->assertSessionHasErrors(['rider_cash' => 'Riders still hold Rs 250 — settle it on the Riders screen, or a manager carries it over.']);
    $close(['carry_rider_cash' => true])->assertSessionHasErrors(['pin' => 'Carrying over the Rs 250 riders hold needs a manager PIN.']);

    $manager = Admin::factory()->forBranches($this->home)->withRole(Role::factory()->withRoutes(Shift::MANAGER_ROUTE)->create())->create(['name' => 'Sana', 'pin' => '4321']);
    $close(['carry_rider_cash' => true, 'pin' => '4321'])->assertSessionHasNoErrors();

    expect($this->shift->fresh())->status->value->toBe('closed')->rider_cash_carried->toBe('250.00')->approved_by->toBe($manager->id);
    expect(Delivery::cashHeld($this->home->id))->toBe(250.0); // still with the rider, to settle in a later shift
});
