<?php

use App\Actions\MarkKitchenReady;
use App\Enums\DesignationType;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PrintDocument;
use App\Enums\StockMovementType;
use App\Enums\TableStatus;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Designation;
use App\Models\DiningTable;
use App\Models\Employee;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\ReadyItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Support\CurrentBranch;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Settings\SettingsResolver;
use App\Support\StockLedger;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Waiter app (PLAN §4.11): table grid with live status, dine-in orders from the phone
 * (source waiter_app, the waiter's own name), more items, mark ready items served, ask
 * for the bill (POS alert + pre-bill at an open counter), ready alerts, PIN landing.
 */

const WAITER_ROUTES = ['waiter.index', 'waiter.table', 'waiter.orders.store', 'waiter.orders.update', 'waiter.orders.serve', 'waiter.orders.bill'];

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];

    $grill = KitchenStation::factory()->forBranch($this->home)->create(['name' => 'Grill']);
    $fryer = KitchenStation::factory()->forBranch($this->home)->create(['name' => 'Fryer']);
    $burgers = Category::factory()->forBranch($this->home)->create(['name' => 'Burgers', 'kitchen_station_id' => $grill->id]);
    $drinks = Category::factory()->forBranch($this->home)->create(['name' => 'Drinks']);
    $this->zinger = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Zinger', 'price' => 600, 'category_id' => $burgers->id]);
    $this->fries = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Fries', 'price' => 250, 'category_id' => $burgers->id, 'kitchen_station_id' => $fryer->id]);
    $this->coke = ReadyItem::factory()->forBranch($this->home)->create(['name' => 'Coke', 'price' => 150, 'category_id' => $drinks->id]);
    app(StockLedger::class)->record($this->coke, StockMovementType::Opening, 50);

    $this->table = DiningTable::factory()->forBranch($this->home)->create(['name' => 'T5']);
    $this->counter = CashCounter::factory()->forBranch($this->home)->create(['name' => 'Counter 1']);
});

function waiterSetting(string $group, string $key, mixed $value): void
{
    $branch = test()->home;
    Setting::query()->updateOrCreate(['branch_id' => $branch->id, 'group' => $group, 'key' => $key], ['value' => $value]);
    app(SettingsResolver::class)->forget($branch->id);
}

/** A waiter (staff record + login) of the home branch, signed in. */
function waiter(array $routes = WAITER_ROUTES, string $name = 'Bilal'): Admin
{
    $admin = loginAdminWithRoutes($routes, test()->home);
    test()->waiterEmployee = Employee::factory()->forBranch(test()->home)->create([
        'name' => $name,
        'admin_id' => $admin->id,
        'designation_id' => Designation::factory()->type(DesignationType::Waiter)->create()->id,
    ]);

    return $admin;
}

/** The cashier's shift is open (waiter orders need one in the branch). */
function waiterCounter(): Shift
{
    return Shift::factory()->create(['branch_id' => test()->home->id, 'cash_counter_id' => test()->counter->id]);
}

function waiterLine(MenuItem|ReadyItem $item, int $quantity = 1, array $extra = []): array
{
    return ['type' => $item instanceof ReadyItem ? 'ready_item' : 'menu_item', 'id' => $item->uuid, 'quantity' => $quantity, ...$extra];
}

function waiterPlace(array $items, array $extra = []): Order
{
    test()->withSession(test()->atHome)
        ->post(route('waiter.orders.store', test()->table), ['items' => $items, ...$extra])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('waiter.table', test()->table));

    return Order::query()->latest('id')->firstOrFail();
}

/** The kitchen finishes every ticket still cooking. */
function waiterReadyAll(Order $order): void
{
    $order->tickets()->whereIn('status', [KitchenStatus::Pending, KitchenStatus::Preparing])->get()->each(fn (KitchenTicket $t) => app(MarkKitchenReady::class)->handle($t, null, [], Admin::factory()->superAdmin()->create()));
}

it('shows the table grid with the open orders, ready counts and mine, without numeric ids', function () {
    waiterCounter();
    $admin = waiter();
    $order = waiterPlace([waiterLine($this->zinger), waiterLine($this->fries), waiterLine($this->coke, 2)], ['guests' => 3]);
    waiterReadyAll($order); // both stations ready
    DiningTable::factory()->forBranch($this->other)->create(['name' => 'Theirs']);
    DiningTable::factory()->forBranch($this->home)->create(['name' => 'T1', 'area_id' => $this->table->area_id]);

    // one line back to cooking so the counts differ
    $order->items()->where('item_name', 'Fries')->update(['kitchen_status' => KitchenStatus::Preparing]);

    $response = $this->actingAs($admin, 'admin')->withSession($this->atHome)->get(route('waiter.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('waiter/Index')
            ->where('shiftOpen', true)
            ->where('me', $this->waiterEmployee->uuid)
            ->has('tables', 2)
            ->where('tables.0.name', 'T1')
            ->where('tables.0.order', null)
            ->where('tables.1.id', $this->table->uuid)
            ->where('tables.1.status.value', 'occupied')
            ->where('tables.1.order.id', $order->uuid)
            ->where('tables.1.order.mine', true)
            ->where('tables.1.order.waiter', 'Bilal')
            ->where('tables.1.order.guests', 3)
            ->where('tables.1.order.items', 4)
            ->where('tables.1.order.ready', 1)
            ->where('tables.1.order.cooking', 1)
            ->where('tables.1.order.bill_requested', false));
    expectNoNumericIds($response->inertiaProps());

    // another branch's table is not there
    $this->withSession($this->atHome)->get(route('waiter.table', DiningTable::query()->withoutGlobalScopes()->where('name', 'Theirs')->sole()))->assertNotFound();
});

it('takes a dine-in order at the table: waiter app source, the waiter’s name, kitchen tickets, table occupied', function () {
    waiterCounter();
    waiter();

    $order = waiterPlace([waiterLine($this->zinger, 2, ['notes' => 'No mayo']), waiterLine($this->coke)], ['guests' => 4, 'notes' => 'Window seat']);

    expect($order)
        ->source->toBe(OrderSource::WaiterApp)
        ->type->toBe(OrderType::DineIn)
        ->status->toBe(OrderStatus::Placed)
        ->table_id->toBe($this->table->id)
        ->waiter_id->toBe($this->waiterEmployee->id)
        ->guests->toBe(4)
        ->notes->toBe('Window seat')
        ->grand_total->not->toBe('0.00');
    expect($order->items()->pluck('item_name')->all())->toBe(['Zinger', 'Coke']);
    expect(KitchenTicket::query()->count())->toBe(1); // the coke needs no kitchen
    expect($this->table->refresh()->status)->toBe(TableStatus::Occupied);
    expect($this->coke->refresh()->current_stock)->toBe('49.000');

    // the table screen shows it
    $response = $this->withSession($this->atHome)->get(route('waiter.table', $this->table))
        ->assertInertia(fn (Assert $page) => $page
            ->component('waiter/Table')
            ->where('table.name', 'T5')
            ->where('order.id', $order->uuid)
            ->where('order.source', 'Waiter app')
            ->has('order.lines', 2)
            ->where('order.lines.0.kitchen_status.value', 'pending')
            ->has('items')
            ->where('shiftOpen', true));
    expectNoNumericIds($response->inertiaProps());

    // a second new order on the busy table is refused
    $this->withSession($this->atHome)->post(route('waiter.orders.store', $this->table), ['items' => [waiterLine($this->coke)]])
        ->assertSessionHasErrors(['table' => "T5 already has {$order->code()} open — add the items to that order."]);
});

it('needs an open shift in the branch, an active table and dine-in switched on', function () {
    waiter();

    $this->withSession($this->atHome)->post(route('waiter.orders.store', $this->table), ['items' => [waiterLine($this->coke)]])
        ->assertSessionHasErrors(['order' => 'No cash counter is open — ask the cashier to open a shift first.']);
    expect(Order::query()->count())->toBe(0);

    waiterCounter();
    $this->table->update(['is_active' => false]);
    $this->withSession($this->atHome)->post(route('waiter.orders.store', $this->table), ['items' => [waiterLine($this->coke)]])
        ->assertSessionHasErrors(['table' => 'This table is switched off.']);

    $this->table->update(['is_active' => true]);
    waiterSetting('orders', 'dine_in', false);
    $this->withSession($this->atHome)->post(route('waiter.orders.store', $this->table), ['items' => [waiterLine($this->coke)]])
        ->assertSessionHasErrors('type');
});

it('adds items without touching what the cashier set: customer, discount, service charge; no discounts from the phone', function () {
    waiterCounter();
    waiter();
    $order = waiterPlace([waiterLine($this->zinger)], ['guests' => 2]);

    // the cashier set a customer, a 10% discount and removed the service charge
    $customer = Customer::factory()->create(['name' => 'Ayesha']);
    $cashier = loginAdminWithRoutes(['pos.orders.update', 'orders.discount', 'orders.service-charge'], $this->home);
    Shift::factory()->create(['branch_id' => $this->home->id, 'cash_counter_id' => CashCounter::factory()->forBranch($this->home)->create()->id, 'opened_by' => $cashier->id]);
    waiterSetting('approvals', 'pin_discount_above', 50);
    waiterSetting('approvals', 'pin_remove_service_charge', false);
    $this->withSession($this->atHome)->put(route('pos.orders.update', $order), [
        'action' => 'save', 'table' => $this->table->uuid, 'waiter' => $this->waiterEmployee->uuid, 'guests' => 2, 'customer' => $customer->uuid,
        'discount' => ['type' => 'percent', 'value' => 10, 'reason' => 'Regular'], 'remove_service_charge' => true,
    ])->assertSessionHasNoErrors();

    // the waiter adds fries (a line discount and another table in the payload are ignored)
    $this->actingAs(Admin::query()->find($this->waiterEmployee->admin_id), 'admin');
    $elsewhere = DiningTable::factory()->forBranch($this->home)->create();
    $this->withSession($this->atHome)->put(route('waiter.orders.update', $order), [
        'items' => [waiterLine($this->fries, 1, ['discount' => ['type' => 'percent', 'value' => 100]])],
        'table' => $elsewhere->uuid, 'type' => 'takeaway', 'remove_service_charge' => false, 'discount' => null,
    ])->assertSessionHasNoErrors()->assertSessionHas('success', "New items added to {$order->code()} and sent to the kitchen.");

    $order->refresh()->load('orderDiscount');
    expect($order)
        ->type->toBe(OrderType::DineIn)
        ->table_id->toBe($this->table->id)
        ->user_id->toBe($customer->id)
        ->guests->toBe(2)
        ->service_charge_removed->toBeTrue()
        ->items_total->toBe('850.00')
        ->discount_total->toBe('85.00'); // 10% of both lines, nothing more
    expect($order->orderDiscount->value)->toBe('10.00');
    expect($order->items()->where('item_name', 'Fries')->sole()->discount_amount)->toBe('0.00');
    expect(KitchenTicket::query()->count())->toBe(2);
});

it('refuses items for a held POS order and for takeaway orders', function () {
    waiterCounter();
    waiter();
    $held = Order::factory()->create(['branch_id' => $this->home->id, 'type' => OrderType::DineIn, 'status' => OrderStatus::Draft, 'table_id' => $this->table->id, 'held_items' => [waiterLine($this->coke)]]);

    $this->withSession($this->atHome)->put(route('waiter.orders.update', $held), ['items' => [waiterLine($this->coke)]])
        ->assertSessionHasErrors(['order' => 'This table’s order is held on the POS — the cashier must send it first.']);
    expect($held->refresh()->held_items)->toHaveCount(1);

    $takeaway = Order::factory()->create(['branch_id' => $this->home->id, 'type' => OrderType::Takeaway, 'status' => OrderStatus::Placed]);
    $this->withSession($this->atHome)->put(route('waiter.orders.update', $takeaway), ['items' => [waiterLine($this->coke)]])
        ->assertSessionHasErrors(['order' => 'Only dine-in orders are taken in the waiter app.']);
    $this->withSession($this->atHome)->post(route('waiter.orders.bill', $takeaway))->assertSessionHasErrors('order');
});

it('marks ready items served: one line or all ready; tickets and the order follow', function () {
    waiterCounter();
    waiter();
    $order = waiterPlace([waiterLine($this->zinger), waiterLine($this->fries)]);

    $this->withSession($this->atHome)->put(route('waiter.orders.serve', $order))
        ->assertSessionHasErrors(['order' => 'Nothing is ready to serve.']);

    waiterReadyAll($order);
    expect($order->refresh()->status)->toBe(OrderStatus::Ready);
    $zinger = $order->items()->where('item_name', 'Zinger')->sole();

    $this->withSession($this->atHome)->put(route('waiter.orders.serve', $order), ['items' => [$zinger->uuid]])
        ->assertSessionHasNoErrors()->assertSessionHas('success', '1 item served.');
    expect($zinger->refresh())->kitchen_status->toBe(KitchenStatus::Served)->served_at->not->toBeNull();
    expect($zinger->ticket->status)->toBe(KitchenStatus::Served);
    expect($order->refresh()->status)->toBe(OrderStatus::Ready); // fries still waiting on the pass

    $this->withSession($this->atHome)->put(route('waiter.orders.serve', $order))
        ->assertSessionHasNoErrors()->assertSessionHas('success', '1 item served.');
    expect($order->refresh()->status)->toBe(OrderStatus::Served);
    expect(KitchenTicket::query()->onBoard()->count())->toBe(0);
    expect($order->histories()->get()->last()->to_status)->toBe(OrderStatus::Served);
});

it('asks for the bill: POS alert, pre-bill at an open counter, cleared by new items, refused when paid', function () {
    waiterCounter();
    $printer = Printer::factory()->forBranch($this->home)->create(['name' => 'Counter printer']);
    $this->counter->update(['receipt_printer_id' => $printer->id]);
    $admin = waiter();
    $order = waiterPlace([waiterLine($this->coke, 2)]);

    $this->travel(1)->seconds();
    $before = $this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['orders', 'floor']]))->json();
    expect($before['versions']['floor'])->toBeGreaterThan(0);

    $this->withSession($this->atHome)->post(route('waiter.orders.bill', $order))
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Bill asked for — printing at Counter printer.');

    expect($order->refresh())->bill_requested_at->not->toBeNull()->bill_requested_by->toBe($admin->id)->billRequested()->toBeTrue();
    expect(PrintJob::query()->sole())->document_type->toBe(PrintDocument::PreBill)->printer_id->toBe($printer->id)
        ->title->toBe("Bill · {$order->code()} · T5 (waiter)");

    $this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['orders'], 'since' => $before['stamp']]))
        ->assertJsonPath('events.orders.0.kind', 'bill')
        ->assertJsonPath('events.orders.0.id', $order->uuid)
        ->assertJsonPath('events.orders.0.table', $this->table->uuid)
        ->assertJsonPath('events.orders.0.by', $admin->name);

    // the POS sees it on the open order
    loginAdminWithRoutes(['pos.index'], $this->home);
    $this->withSession($this->atHome)->get(route('pos.index', ['order' => $order->uuid]))
        ->assertInertia(fn (Assert $page) => $page->where('order.bill_requested', true)->where('order.bill_requested_by.name', $admin->name));

    // more items: the request is out of date
    $this->actingAs($admin, 'admin');
    $this->withSession($this->atHome)->put(route('waiter.orders.update', $order), ['items' => [waiterLine($this->coke)]])->assertSessionHasNoErrors();
    expect($order->refresh()->bill_requested_at)->toBeNull();

    // without a counter printer the cashier is only told; paid orders can't ask
    $this->counter->update(['receipt_printer_id' => null]);
    $this->withSession($this->atHome)->post(route('waiter.orders.bill', $order))
        ->assertSessionHas('success', 'Bill asked for — the cashier has been told.');
    $order->forceFill(['paid_total' => $order->grand_total])->save();
    $this->withSession($this->atHome)->post(route('waiter.orders.bill', $order))
        ->assertSessionHasErrors(['order' => "Order {$order->code()} is already paid."]);
});

it('alerts the waiter when a station’s items or the whole order are ready', function () {
    waiterCounter();
    waiter();
    $order = waiterPlace([waiterLine($this->zinger), waiterLine($this->fries)]);
    $this->travel(1)->seconds();
    $since = $this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['orders']]))->json('stamp');

    $grill = $order->tickets()->whereHas('station', fn ($q) => $q->where('name', 'Grill'))->sole();
    app(MarkKitchenReady::class)->handle($grill, null, [], Admin::factory()->superAdmin()->create());

    $events = $this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['orders'], 'since' => $since]))->json('events.orders');
    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray(['kind' => 'items_ready', 'station' => 'Grill', 'label' => 'T5', 'table' => $this->table->uuid, 'waiter' => $this->waiterEmployee->uuid]);

    waiterReadyAll($order);
    $kinds = collect($this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['orders'], 'since' => $since]))->json('events.orders'))->pluck('kind')->all();
    expect($kinds)->toBe(['items_ready', 'items_ready', 'ready']);
});

it('checks each waiter permission and lands waiters on the waiter app after PIN sign-in', function () {
    waiterCounter();
    waiter(['waiter.index', 'waiter.table', 'waiter.orders.store']);
    $order = waiterPlace([waiterLine($this->coke)]);

    $this->withSession($this->atHome)->put(route('waiter.orders.serve', $order))->assertForbidden();
    $this->withSession($this->atHome)->post(route('waiter.orders.bill', $order))->assertForbidden();

    // a waiter-only role lands on the tables; a cashier on the dashboard
    auth('admin')->logout();
    PermissionCatalog::sync();
    $pinWaiter = Admin::factory()->withRole(Role::factory()->withRoutes(...WAITER_ROUTES)->create())->forBranches($this->home)
        ->create(['username' => 'bilal']);
    $this->post(route('login.pin'), ['username' => 'bilal', 'pin' => '1234'])->assertRedirect(route('waiter.index'));

    expect($pinWaiter->homeRoute())->toBe('waiter.index')
        ->and(Admin::factory()->withRole(Role::factory()->withRoutes('pos.index', 'waiter.index')->create())->create()->homeRoute())->toBe('dashboard')
        ->and(Admin::factory()->withRole(Role::factory()->withRoutes('kitchen.index')->create())->create()->homeRoute())->toBe('kitchen.index');
});
