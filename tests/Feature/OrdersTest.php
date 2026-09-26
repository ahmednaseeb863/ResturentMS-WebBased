<?php

use App\Enums\ConsumptionStatus;
use App\Enums\DiscountScope;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\TableStatus;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DiningTable;
use App\Models\Discount;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReadyItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Support\CurrentBranch;
use App\Support\Settings\SettingsResolver;
use App\Support\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * POS & orders (PLAN §4.10, §4.13, §5): cart checks, hold / send, kitchen tickets,
 * ready-item stock, pricing (service charge + tax on the whole bill), void, cancel.
 */

const POS_ROUTES = ['pos.index', 'pos.orders.store', 'pos.orders.update', 'pos.orders.discard', 'pos.customers.store', 'orders.index', 'orders.show'];

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];

    $this->grill = KitchenStation::factory()->forBranch($this->home)->create(['name' => 'Grill']);
    $this->burgers = Category::factory()->forBranch($this->home)->create(['name' => 'Burgers', 'kitchen_station_id' => $this->grill->id]);
    $this->drinks = Category::factory()->forBranch($this->home)->create(['name' => 'Drinks']);
    $this->zinger = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Zinger', 'price' => 600, 'category_id' => $this->burgers->id]);
    $this->coke = ReadyItem::factory()->forBranch($this->home)->create(['name' => 'Coke', 'price' => 150, 'category_id' => $this->drinks->id]);
    app(StockLedger::class)->record($this->coke, StockMovementType::Opening, 10);

});

function cashier(array $routes = [], ?Branch $branch = null): Admin
{
    $branch ??= test()->home;
    $admin = loginAdminWithRoutes([...POS_ROUTES, ...$routes], $branch);
    $counter = CashCounter::factory()->forBranch($branch)->create();
    Shift::factory()->create(['branch_id' => $branch->id, 'cash_counter_id' => $counter->id, 'opened_by' => $admin->id]);

    return $admin;
}

function orderSetting(Branch $branch, string $group, string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['branch_id' => $branch->id, 'group' => $group, 'key' => $key], ['value' => $value]);
    app(SettingsResolver::class)->forget($branch->id);
}

function cartLine(Model $item, int $quantity = 1, array $extra = []): array
{
    return ['type' => $item->getMorphClass(), 'id' => $item->uuid, 'quantity' => $quantity, ...$extra];
}

function manager(Branch $branch, string $route, string $pin = '4321'): Admin
{
    return Admin::factory()->forBranches($branch)->withRole(Role::factory()->withRoutes($route)->create())->create(['name' => 'Sana', 'pin' => $pin]);
}

it('shows the POS menu, tables and rules without numeric ids', function () {
    cashier();
    $table = DiningTable::factory()->forBranch($this->home)->create(['name' => 'T1']);
    MenuItem::factory()->forBranch($this->other)->create(['name' => 'Elsewhere']);

    $response = $this->withSession($this->atHome)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
        ->component('pos/Index')
        ->has('items', 2)
        ->where('items.0.name', 'Zinger')
        ->where('items.1.stock', 10)
        ->where('tables.0.id', $table->uuid)
        ->where('rules.types', ['dine_in', 'takeaway', 'delivery']));

    expectNoNumericIds($response->inertiaProps());
});

it('needs an open shift to take orders', function () {
    loginAdminWithRoutes(POS_ROUTES, $this->home);

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->zinger)]])
        ->assertSessionHasErrors(['order' => 'Open your shift on a cash counter before taking orders.']);
    expect(Order::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('places a takeaway order: lines, kitchen ticket, ready items out of stock, tax on the bill', function () {
    $cashier = cashier();
    orderSetting($this->home, 'tax', 'enabled', true);
    orderSetting($this->home, 'orders', 'number_prefix', 'GUL-');
    $this->travelTo(CarbonImmutable::parse('2026-09-26 01:30', 'Asia/Karachi'));

    $this->withSession($this->atHome)->post(route('pos.orders.store'), [
        'action' => 'send',
        'type' => 'takeaway',
        'items' => [cartLine($this->zinger, 2, ['notes' => 'No mayo', 'price' => 1]), cartLine($this->coke, 3)],
    ])->assertSessionHasNoErrors()->assertRedirect(route('pos.index'))
        ->assertSessionHas('success', 'Order GUL-001 placed and sent to the kitchen.');

    $order = Order::query()->sole();
    expect($order)
        ->status->toBe(OrderStatus::Placed)
        ->order_number->toBe('GUL-001')
        ->business_date->toDateString()->toBe('2026-09-25')
        ->created_by->toBe($cashier->id)
        ->items_total->toBe('1650.00')   // 2 × 600 + 3 × 150 — prices from the menu
        ->tax_rate->toBe('16.00')
        ->tax_total->toBe('264.00')
        ->grand_total->toBe('1914.00');

    $zinger = $order->items()->where('sellable_type', 'menu_item')->sole();
    expect($zinger)
        ->kitchen_status->toBe(KitchenStatus::Pending)
        ->kitchen_station_id->toBe($this->grill->id)     // from the category
        ->consumption_status->toBe(ConsumptionStatus::NotRequired)
        ->notes->toBe('No mayo');
    expect($order->items()->where('sellable_type', 'ready_item')->sole()->kitchen_status)->toBeNull();

    $ticket = KitchenTicket::query()->sole();
    expect($ticket)->number->toBe(1)->kitchen_station_id->toBe($this->grill->id)->code()->toBe('KOT-001');
    expect($zinger->kitchen_ticket_id)->toBe($ticket->id);

    expect($this->coke->fresh()->current_stock)->toBe('7.000');
    expect(StockMovement::query()->where('type', StockMovementType::Sale)->sole())
        ->quantity->toBe('-3.000')
        ->business_date->toDateString()->toBe('2026-09-25');
});

it('holds an order without a number and places it later', function () {
    cashier();

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'hold', 'type' => 'takeaway', 'items' => [cartLine($this->coke, 2)]])
        ->assertSessionHas('success', 'Order held — recall it from Open Orders.');

    $order = Order::query()->sole();
    expect($order)->status->toBe(OrderStatus::Draft)->number->toBeNull()->grand_total->toBe('300.00');
    expect($order->held_items[0])->toMatchArray(['type' => 'ready_item', 'id' => $this->coke->uuid, 'quantity' => 2, 'name' => 'Coke']);
    expect($order->items()->count())->toBe(0);
    expect($this->coke->fresh()->current_stock)->toBe('10.000'); // stock moves only when sent

    $this->get(route('pos.index', ['order' => $order->uuid]))->assertInertia(fn (Assert $page) => $page
        ->where('order.is_draft', true)
        ->where('order.held_items.0.id', $this->coke->uuid)
        ->where('openOrders.0.id', $order->uuid));

    $this->put(route('pos.orders.update', $order), ['action' => 'send', 'items' => [cartLine($this->coke, 3)]])
        ->assertSessionHas('success', 'Order #001 placed.');

    expect($order->fresh())->status->toBe(OrderStatus::Ready)->order_number->toBe('001')->held_items->toBeNull();
    expect($order->histories()->pluck('to_status')->map->value->all())->toBe(['draft', 'ready']);
    expect($this->coke->fresh()->current_stock)->toBe('7.000');
});

it('runs dine-in orders on a table with service charge, one open order per table', function () {
    cashier();
    orderSetting($this->home, 'service_charge', 'enabled', true);
    $table = DiningTable::factory()->forBranch($this->home)->create(['name' => 'T5']);

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'dine_in', 'items' => [cartLine($this->zinger)]])
        ->assertSessionHasErrors(['table' => 'Pick the table of this dine-in order.']);

    $this->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'dine_in', 'table' => $table->uuid, 'guests' => 4, 'items' => [cartLine($this->zinger)]])
        ->assertSessionHasNoErrors();

    $order = Order::query()->sole();
    expect($order)->table_id->toBe($table->id)->guests->toBe(4)
        ->service_charge_rate->toBe('5.00')->service_charge->toBe('30.00')->grand_total->toBe('630.00');
    expect($table->fresh()->status)->toBe(TableStatus::Occupied);
    $this->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
        ->where('openOrders.0.label', 'T5')
        ->where('tables.0.order.code', '#001'));

    $this->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'dine_in', 'table' => $table->uuid, 'items' => [cartLine($this->coke)]])
        ->assertSessionHasErrors(['table' => 'T5 already has #001 open — add the items to that order.']);

    // more items later: a new kitchen ticket on the same order
    $this->put(route('pos.orders.update', $order), ['action' => 'send', 'table' => $table->uuid, 'items' => [cartLine($this->zinger)]])
        ->assertSessionHas('success', 'New items added to #001 and sent to the kitchen.');
    expect(KitchenTicket::query()->pluck('number')->all())->toBe([1, 2]);
    expect($order->fresh())->items_total->toBe('1200.00')->grand_total->toBe('1260.00');

    // the table can't be trashed while the order is open
    expect(fn () => $table->fresh()->trash())->toThrow(TrashNotAllowed::class);
    // one open order per table in MySQL too
    expect(fn () => Order::factory()->create(['branch_id' => $this->home->id, 'table_id' => $table->id]))->toThrow(QueryException::class);
});

it('checks the cart against the menu', function () {
    cashier();
    $size = $this->zinger->variants()->create(['name' => 'Large', 'price' => 800]);
    $group = ModifierGroup::factory()->forBranch($this->home)->create(['name' => 'Sauce', 'min_select' => 1, 'max_select' => 1]);
    $garlic = $group->modifiers()->create(['name' => 'Garlic', 'price' => 50]);
    $group->modifiers()->create(['name' => 'Chilli', 'price' => 0]);
    $this->zinger->modifierGroups()->attach($group->id);
    $soldOut = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Wings', 'is_sold_out' => true]);
    $dineOnly = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Platter', 'available_for' => ['dine_in']]);
    $foreign = MenuItem::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'delivery', 'items' => [
        cartLine($this->zinger),
        cartLine($soldOut),
        cartLine($dineOnly),
        cartLine($foreign),
    ]])->assertSessionHasErrors([
        'items.0' => 'Pick a size for “Zinger”.',
        'items.1' => '“Wings” is sold out today.',
        'items.2' => '“Platter” is not sold for delivery.',
        'items.3' => 'An item in the cart is no longer on the menu — remove it.',
    ]);

    $this->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->zinger, 1, ['variant' => $size->uuid])]])
        ->assertSessionHasErrors(['items.0' => 'Pick Sauce for “Zinger”.']);

    $this->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [
        cartLine($this->zinger, 2, ['variant' => $size->uuid, 'modifiers' => [$garlic->uuid]]),
    ]])->assertSessionHasNoErrors();

    $item = OrderItem::query()->sole();
    expect($item)->item_name->toBe('Zinger')->variant_name->toBe('Large')
        ->unit_price->toBe('800.00')->modifiers_total->toBe('50.00')->line_total->toBe('1700.00');
    expect($item->modifiers()->sole())->name->toBe('Garlic')->price->toBe('50.00');
});

it('sells deals as a line with its picks, in stock and in the kitchen', function () {
    cashier();
    $deal = Deal::factory()->forBranch($this->home)->create(['name' => 'Zinger Meal', 'price' => 700]);
    $burger = $deal->slots()->create(['name' => 'Burger', 'quantity' => 1]);
    $burger->options()->create(['sellable_type' => 'menu_item', 'sellable_id' => $this->zinger->id]);
    $drink = $deal->slots()->create(['name' => 'Drink', 'quantity' => 2]);
    $sprite = ReadyItem::factory()->forBranch($this->home)->create(['name' => 'Sprite']);
    $drink->options()->create(['sellable_type' => 'ready_item', 'sellable_id' => $this->coke->id]);
    $spriteOption = $drink->options()->create(['sellable_type' => 'ready_item', 'sellable_id' => $sprite->id, 'extra_price' => 20]);

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($deal)]])
        ->assertSessionHasErrors(['items.0' => 'Pick Drink for the deal “Zinger Meal”.']);

    $this->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [
        cartLine($deal, 2, ['picks' => [['slot' => $drink->uuid, 'option' => $spriteOption->uuid]]]),
    ]])->assertSessionHasNoErrors();

    $parent = OrderItem::query()->whereNull('parent_order_item_id')->sole();
    expect($parent)->sellable_type->toBe('deal')->unit_price->toBe('720.00')->line_total->toBe('1440.00')->kitchen_status->toBeNull();
    expect($parent->children->map(fn ($c) => [$c->item_name, $c->quantity, $c->line_total])->all())
        ->toBe([['Zinger', 2, '0.00'], ['Sprite', 4, '0.00']]);
    expect($parent->children[0]->kitchen_status)->toBe(KitchenStatus::Pending);
    expect(StockMovement::query()->where('stockable_id', $sprite->id)->where('type', 'sale')->value('quantity'))->toBe('-4.000');
});

it('refuses ready items beyond stock when the branch blocks it, else lets stock go negative', function () {
    cashier();
    orderSetting($this->home, 'inventory', 'out_of_stock', 'block');

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->coke, 8), cartLine($this->coke, 3)]])
        ->assertSessionHasErrors(['items.0' => 'Only 10 of “Coke” left in stock.']);

    orderSetting($this->home, 'inventory', 'out_of_stock', 'warn');

    $this->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->coke, 12)]])->assertSessionHasNoErrors();
    expect($this->coke->fresh()->current_stock)->toBe('-2.000');
});

it('gives discounts with permission, and a manager PIN above the limit', function () {
    $cashier = cashier();
    $tenOff = Discount::factory()->forBranch($this->home)->create(['name' => 'Staff 10%', 'value' => 10, 'applies_to' => DiscountScope::Order]);

    $send = fn (array $extra) => $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->zinger, 2)], ...$extra]);

    $send(['discount' => ['discount' => $tenOff->uuid]])->assertSessionHasErrors(['discount' => 'You may not give discounts.']);

    $this->actingAs($cashier = cashier(['orders.discount']), 'admin');
    manager($this->home, 'orders.discount');

    // predefined without approval: 10% of 1200
    $send(['discount' => ['discount' => $tenOff->uuid]])->assertSessionHasNoErrors();
    $order = Order::query()->latest('id')->first();
    expect($order)->discount_total->toBe('120.00')->grand_total->toBe('1080.00');
    expect($order->orderDiscount)->name->toBe('Staff 10%')->amount->toBe('120.00')->approved_by->toBeNull();

    // typed-in 25% is above the 10% limit → PIN
    $manual = ['discount' => ['type' => 'percent', 'value' => 25, 'reason' => 'Regular']];
    $send($manual)->assertSessionHasErrors(['pin' => 'A manager must enter their PIN.']);
    $send([...$manual, 'pin' => '4321'])->assertSessionHasNoErrors();
    expect(Order::query()->latest('id')->first()->orderDiscount->approvedBy->name)->toBe('Sana');

    // line discount without a reason
    $send(['items' => [cartLine($this->zinger, 1, ['discount' => ['type' => 'fixed', 'value' => 50]])]])
        ->assertSessionHasErrors(['items.0' => 'Say why “Zinger” is discounted.']);
});

it('removes service charge only with permission, setting and PIN', function () {
    cashier();
    orderSetting($this->home, 'service_charge', 'enabled', true);
    $table = DiningTable::factory()->forBranch($this->home)->create();
    $data = ['action' => 'send', 'type' => 'dine_in', 'table' => $table->uuid, 'items' => [cartLine($this->zinger)], 'remove_service_charge' => true];

    $this->withSession($this->atHome)->post(route('pos.orders.store'), $data)
        ->assertSessionHasErrors(['remove_service_charge' => 'You may not remove the service charge.']);

    $this->actingAs(cashier(['orders.service-charge']), 'admin');
    manager($this->home, 'orders.service-charge');
    $this->post(route('pos.orders.store'), $data)->assertSessionHasErrors('pin');
    $this->post(route('pos.orders.store'), [...$data, 'pin' => '4321'])->assertSessionHasNoErrors();

    $order = Order::query()->sole();
    expect($order)->service_charge_removed->toBeTrue()->service_charge->toBe('0.00')->grand_total->toBe('600.00');

    // put back from the order page
    $this->put(route('orders.service-charge', $order), ['remove' => false])->assertSessionHasNoErrors();
    expect($order->fresh())->service_charge->toBe('30.00');
});

it('voids part of a line: split row, stock back, bill recalculated, manager PIN', function () {
    $cashier = cashier(['orders.items.void']);
    manager($this->home, 'orders.items.void');

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->coke, 4), cartLine($this->zinger)]]);
    $order = Order::query()->sole();
    $coke = $order->items()->where('sellable_type', 'ready_item')->sole();

    $this->put(route('orders.items.void', [$order, $coke]), ['quantity' => 3, 'reason' => 'Changed mind'])->assertSessionHasErrors('pin');
    $this->put(route('orders.items.void', [$order, $coke]), ['quantity' => 5, 'reason' => 'x', 'pin' => '4321'])->assertSessionHasErrors('quantity');
    $this->put(route('orders.items.void', [$order, $coke]), ['quantity' => 3, 'reason' => 'Changed mind', 'pin' => '4321'])->assertSessionHasNoErrors();

    expect($coke->fresh()->quantity)->toBe(1);
    $voided = OrderItem::query()->whereNotNull('voided_at')->sole();
    expect($voided)->quantity->toBe(3)->split_from_id->toBe($coke->id)->void_reason->toBe('Changed mind')
        ->void_approved_by->not->toBeNull()->voided_by->toBe($cashier->id);
    expect($this->coke->fresh()->current_stock)->toBe('9.000'); // 10 − 4 + 3
    expect($order->fresh())->items_total->toBe('750.00')->grand_total->toBe('750.00');

    // wasted: nothing goes back to stock
    $this->put(route('orders.items.void', [$order, $coke]), ['quantity' => 1, 'reason' => 'Spilled', 'wasted' => true, 'pin' => '4321'])->assertSessionHasNoErrors();
    expect($this->coke->fresh()->current_stock)->toBe('9.000');
    $this->put(route('orders.items.void', [$order, $coke]), ['quantity' => 1, 'reason' => 'x', 'pin' => '4321'])
        ->assertSessionHasErrors(['quantity' => '“Coke” is already voided.']);
});

it('cancels an order: lines voided, stock back, table freed; discards held orders', function () {
    $cashier = cashier(['orders.cancel']);
    orderSetting($this->home, 'approvals', 'pin_void', false);
    $table = DiningTable::factory()->forBranch($this->home)->create();

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'dine_in', 'table' => $table->uuid, 'items' => [cartLine($this->coke, 2)]]);
    $order = Order::query()->sole();

    $this->put(route('orders.cancel', $order), [])->assertSessionHasErrors(['reason' => 'Say why the order is cancelled.']);
    $this->put(route('orders.cancel', $order), ['reason' => 'Customer left'])->assertSessionHasNoErrors();

    expect($order->fresh())->status->toBe(OrderStatus::Cancelled)->cancel_reason->toBe('Customer left')->cancelled_by->toBe($cashier->id)
        ->grand_total->toBe('300.00'); // shows what was cancelled
    expect($order->items()->whereNull('voided_at')->count())->toBe(0);
    expect($this->coke->fresh()->current_stock)->toBe('10.000');
    expect($table->fresh()->status)->toBe(TableStatus::Available);
    $this->put(route('orders.cancel', $order), ['reason' => 'again'])->assertSessionHasErrors('reason');
    $this->get(route('pos.index', ['order' => $order->uuid]))->assertRedirect(route('orders.show', $order));

    // held order: discarded from the POS
    $this->post(route('pos.orders.store'), ['action' => 'hold', 'type' => 'takeaway', 'items' => [cartLine($this->zinger)]]);
    $held = Order::query()->where('status', 'draft')->sole();
    $this->put(route('pos.orders.discard', $held))->assertRedirect(route('pos.index'));
    expect($held->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('takes delivery orders with customer, address, fee and minimum', function () {
    cashier();
    orderSetting($this->home, 'delivery', 'default_fee', 150);
    orderSetting($this->home, 'delivery', 'min_order_amount', 1000);
    orderSetting($this->home, 'tax', 'enabled', true);
    $customer = Customer::factory()->create(['name' => 'Ali', 'phone' => '03001234567']);
    $home = $customer->addresses()->create(['address' => 'House 5, Street 2', 'area' => 'Gulberg III', 'is_default' => true]);

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'delivery', 'items' => [cartLine($this->zinger)]])
        ->assertSessionHasErrors(['customer' => 'Pick the customer of this delivery.']);

    $this->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'delivery', 'customer' => $customer->uuid, 'address' => $home->uuid, 'items' => [cartLine($this->zinger)]])
        ->assertSessionHasErrors(['order' => 'Delivery orders must be at least Rs 1,000 — this one is Rs 600.']);
    expect(Order::query()->count())->toBe(0); // rolled back

    $this->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'delivery', 'customer' => $customer->uuid, 'address' => $home->uuid, 'items' => [cartLine($this->zinger, 2)]])
        ->assertSessionHasNoErrors();

    $order = Order::query()->with('delivery')->sole();
    expect($order)->delivery_fee->toBe('150.00')->tax_total->toBe('216.00')->grand_total->toBe('1566.00'); // (1200 + 150) × 16%
    expect($order->delivery)->address->toBe('House 5, Street 2, Gulberg III')->phone->toBe('03001234567')->status->value->toBe('pending');
});

it('rounds the bill per the payments setting', function () {
    cashier();
    orderSetting($this->home, 'tax', 'enabled', true);
    orderSetting($this->home, 'payments', 'rounding', '10');

    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->coke)]]);

    expect(Order::query()->sole())->tax_total->toBe('24.00')->round_off->toBe('-4.00')->grand_total->toBe('170.00');
});

it('lists orders with stats and keeps other branches out', function () {
    cashier();
    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->coke, 2)]]);
    $this->post(route('pos.orders.store'), ['action' => 'hold', 'type' => 'takeaway', 'items' => [cartLine($this->coke)]]);
    $order = Order::query()->where('status', '!=', 'draft')->sole();

    $foreign = Order::factory()->create(['branch_id' => $this->other->id]);

    $response = $this->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page
        ->component('orders/Index')
        ->has('orders.data', 2)
        ->where('stats.orders', 1)
        ->where('stats.total', 300)
        ->where('stats.held', 1));
    expectNoNumericIds($response->inertiaProps());

    $response = $this->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->component('orders/Show')
        ->where('order.code', '#001')
        ->has('order.lines', 1)
        ->has('history', 1));
    expectNoNumericIds($response->inertiaProps());

    $this->get(route('orders.show', $foreign))->assertNotFound();
    $this->get(route('pos.index', ['order' => $foreign->uuid]))->assertInertia(fn (Assert $page) => $page->where('order', null));
});

it('never deletes orders or their lines', function () {
    cashier();
    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => [cartLine($this->coke)]]);

    expect(fn () => Order::query()->first()->delete())->toThrow(PermanentDeleteNotAllowed::class);
    expect(fn () => OrderItem::query()->first()->delete())->toThrow(PermanentDeleteNotAllowed::class);
});
