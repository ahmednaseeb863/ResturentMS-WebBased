<?php

use App\Enums\CashMovementType;
use App\Enums\ConsumptionStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemConsumption;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Support\CurrentBranch;
use App\Support\Settings\SettingsResolver;
use App\Support\ShiftSummary;
use App\Support\StockLedger;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Inventory, the rest (PLAN §4.16 / Phase 13): suppliers, purchases into stock, supplier
 * payments (cash from the shift / bank), purchase returns, waste, stock counts, pending
 * consumption review with auto-confirm and corrections, low stock.
 */

const INV_ROUTES = [
    'suppliers.index', 'suppliers.show', 'suppliers.store', 'suppliers.update', 'suppliers.destroy', 'suppliers.restore', 'suppliers.pay',
    'purchases.index', 'purchases.show', 'purchases.create', 'purchases.store', 'purchases.return',
    'waste.index', 'waste.store', 'stock-counts.index', 'stock-counts.show', 'stock-counts.store', 'stock-counts.update',
    'stock-counts.cancel', 'stock-counts.approve', 'consumptions.pending', 'consumptions.confirm', 'consumptions.adjust', 'low-stock.index',
];

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];

    $this->kg = Unit::query()->where('short_name', 'kg')->sole();
    $this->g = Unit::query()->where('short_name', 'g')->sole();
    $this->pcs = Unit::query()->where('short_name', 'pcs')->sole();
    $this->crate = Unit::query()->where('short_name', 'crate')->sole();

    $this->chicken = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Chicken', 'alert_level' => 5]);
    app(StockLedger::class)->record($this->chicken, StockMovementType::Opening, 10, 800);
    $drinks = Category::factory()->forBranch($this->home)->create(['name' => 'Drinks']);
    $this->coke = ReadyItem::factory()->forBranch($this->home)->create([
        'name' => 'Coke', 'price' => 150, 'category_id' => $drinks->id,
        'stock_unit_id' => $this->pcs->id, 'purchase_unit_id' => $this->crate->id, 'purchase_unit_factor' => 24,
    ]);

    $this->supplier = Supplier::factory()->create(['name' => 'Fresh Poultry']);
    $this->counter = CashCounter::factory()->forBranch($this->home)->create(['name' => 'Counter 1']);
});

function invSetting(string $group, string $key, mixed $value): void
{
    $branch = test()->home;
    Setting::query()->updateOrCreate(['branch_id' => $branch->id, 'group' => $group, 'key' => $key], ['value' => $value]);
    app(SettingsResolver::class)->forget($branch->id);
}

function invLogin(array $routes = INV_ROUTES): Admin
{
    $admin = loginAdminWithRoutes($routes, test()->home);
    test()->withSession(test()->atHome);

    return $admin;
}

function invShift(Admin $admin): Shift
{
    return test()->shift = Shift::factory()->create(['branch_id' => test()->home->id, 'cash_counter_id' => test()->counter->id, 'opened_by' => $admin->id]);
}

function invLine($item, float $quantity, Unit $unit, ?float $cost = null): array
{
    return ['kind' => $item->getMorphClass(), 'item' => $item->uuid, 'quantity' => $quantity, 'unit' => $unit->uuid, 'unit_cost' => $cost];
}

/** Receive 5 kg chicken at 900 and 2 crates of Coke at 2,400 (subtotal 9,300). */
function invPurchase(array $extra = []): Purchase
{
    test()->post(route('purchases.store'), [
        'supplier' => test()->supplier->uuid,
        'invoice_no' => 'INV-77',
        'lines' => [invLine(test()->chicken, 5, test()->kg, 900), invLine(test()->coke, 2, test()->crate, 2400)],
        ...$extra,
    ])->assertSessionHasNoErrors();

    return Purchase::query()->latest('id')->firstOrFail();
}

// ── Suppliers ────────────────────────────────────────────────────────────

it('manages suppliers shared by all branches, with trash rules', function () {
    invLogin();

    $this->post(route('suppliers.store'), ['name' => 'Fresh Poultry'])->assertSessionHasErrors('name');
    $this->post(route('suppliers.store'), ['name' => 'Metro', 'phone' => '0300 1234567', 'ntn' => '123'])->assertSessionHasNoErrors();
    $metro = Supplier::query()->where('name', 'Metro')->sole();

    $response = $this->get(route('suppliers.index'))->assertInertia(fn (Assert $page) => $page
        ->component('suppliers/Index')->has('suppliers.data', 2)->where('suppliers.data.0.balance', 0));
    expectNoNumericIds($response->inertiaProps());

    $this->delete(route('suppliers.destroy', $metro), ['reason' => 'Closed'])->assertSessionHasNoErrors();
    expect($metro->fresh()->isTrashed())->toBeTrue();
    $this->post(route('suppliers.restore', $metro))->assertSessionHasNoErrors();
    expect(fn () => $metro->delete())->toThrow(PermanentDeleteNotAllowed::class);

    // a supplier with money owed can't be trashed
    invPurchase();
    expect(fn () => $this->supplier->trash())->toThrow(TrashNotAllowed::class, 'not settled');
});

// ── Purchases ────────────────────────────────────────────────────────────

it('receives a purchase into stock with average costs, and lists it without numeric ids', function () {
    invLogin();

    $purchase = invPurchase(['discount' => 300, 'tax' => 500]);

    expect($purchase)->subtotal->toBe('9300.00')->total->toBe('9500.00')->payment_status->toBe(PaymentStatus::Unpaid)
        ->number->toBe(1)->business_date->not->toBeNull();
    expect($purchase->code())->toBe('PUR-0001');
    expect($this->chicken->fresh())->current_stock->toBe('15.000')->avg_cost->toBe('833.3333'); // (10×800 + 5×900) / 15
    expect($this->coke->fresh())->current_stock->toBe('48.000')->avg_cost->toBe('100.0000');
    expect(StockMovement::query()->where('type', StockMovementType::Purchase)->count())->toBe(2);

    $response = $this->get(route('purchases.show', $purchase))->assertInertia(fn (Assert $page) => $page
        ->component('purchases/Show')
        ->has('purchase.items', 2)
        ->where('purchase.items.1.returnable', 2)
        ->where('purchase.due', 9500));
    expectNoNumericIds($response->inertiaProps());

    $this->get(route('purchases.index'))->assertInertia(fn (Assert $page) => $page->has('purchases.data', 1)->where('stats.due', 9500));

    // lines are checked: unit must fit the item
    $this->post(route('purchases.store'), ['supplier' => $this->supplier->uuid, 'lines' => [invLine($this->chicken, 2, $this->pcs, 10)]])
        ->assertSessionHasErrors('lines.0.unit');

    // another branch can't see it
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id]);
    loginAdminWithRoutes(INV_ROUTES, $this->other);
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])->get(route('purchases.show', $purchase))->assertNotFound();
});

it('pays suppliers from the shift drawer or a bank account, per purchase or on account', function () {
    $admin = invLogin();
    $shift = invShift($admin);
    $bank = BankAccount::factory()->forBranches($this->home)->create(['bank_name' => 'HBL']);

    // paid part now in cash when receiving
    $first = invPurchase(['pay' => ['amount' => 1000, 'method' => 'cash']]);
    expect($first)->paid_total->toBe('1000.00')->payment_status->toBe(PaymentStatus::Partial);
    expect(CashMovement::query()->sole())->type->toBe(CashMovementType::SupplierPayment)->amount->toBe('1000.00');
    expect(ShiftSummary::of($shift)->expectedCash())->toBe(4000.0);

    // not more than the drawer holds
    $this->post(route('suppliers.pay', $this->supplier), ['purchase' => $first->uuid, 'amount' => 6000, 'method' => 'cash'])
        ->assertSessionHasErrors('amount');
    // not more than the purchase owes
    $this->post(route('suppliers.pay', $this->supplier), ['purchase' => $first->uuid, 'amount' => 9000, 'method' => 'bank_transfer', 'bank' => $bank->uuid])
        ->assertSessionHasErrors(['amount' => 'PUR-0001 owes only Rs 8,300.']);

    $second = invPurchase();

    // on account, by bank: oldest bill first, the rest to the next
    $this->post(route('suppliers.pay', $this->supplier), ['amount' => 10000, 'method' => 'bank_transfer', 'bank' => $bank->uuid, 'reference' => 'TX-1'])
        ->assertSessionHasNoErrors();
    expect($first->fresh())->paid_total->toBe('9300.00')->payment_status->toBe(PaymentStatus::Paid);
    expect($second->fresh())->paid_total->toBe('1700.00')->payment_status->toBe(PaymentStatus::Partial);
    expect(SupplierPayment::query()->latest('id')->first())->shift_id->toBeNull()->bank_account_id->toBe($bank->id);
    expect($this->supplier->balanceIn($this->home->id))->toBe(7600.0);

    $this->get(route('suppliers.show', $this->supplier))->assertInertia(fn (Assert $page) => $page
        ->component('suppliers/Show')->where('supplier.balance', 7600)->has('payments', 2)->has('purchases', 2));
});

it('returns goods to the supplier: stock out, bill down, never more than received', function () {
    invLogin();
    $purchase = invPurchase();
    $coke = $purchase->items()->where('item_name', 'Coke')->sole();

    $this->post(route('purchases.return', $purchase), ['lines' => [$coke->uuid => 3], 'reason' => 'Dented'])
        ->assertSessionHasErrors(["lines.{$coke->uuid}" => 'Only 2 crate of Coke can go back.']);
    $this->post(route('purchases.return', $purchase), ['lines' => [$coke->uuid => 1], 'reason' => 'Dented'])->assertSessionHasNoErrors();

    expect($this->coke->fresh()->current_stock)->toBe('24.000');
    expect($purchase->fresh())->returned_total->toBe('2400.00')->total->toBe('9300.00');
    expect($purchase->fresh()->due())->toBe(6900.0);
    expect(StockMovement::query()->where('type', StockMovementType::PurchaseReturn)->sole())->quantity->toBe('-24.000');
    expect($coke->fresh()->returnable())->toBe(24.0);
});

// ── Waste ────────────────────────────────────────────────────────────────

it('writes off waste at the average cost', function () {
    invLogin();

    $this->post(route('waste.store'), ['type' => 'waste', 'reason' => 'Spoiled', 'lines' => [invLine($this->chicken, 500, $this->g)]])
        ->assertSessionHasNoErrors();
    expect($this->chicken->fresh()->current_stock)->toBe('9.500');
    $movement = StockMovement::query()->where('type', StockMovementType::Waste)->sole();
    expect($movement)->quantity->toBe('-0.500')->unit_cost->toBe('800.0000');

    // no negative stock unless the setting allows it
    $this->post(route('waste.store'), ['type' => 'damage', 'reason' => 'Dropped', 'lines' => [invLine($this->chicken, 20, $this->kg)]])
        ->assertSessionHasErrors('quantity');

    $response = $this->get(route('waste.index'))->assertInertia(fn (Assert $page) => $page
        ->component('waste/Index')->has('entries.data', 1)->where('entries.data.0.code', 'W-0001')->where('total', 400));
    expectNoNumericIds($response->inertiaProps());
});

// ── Stock counts ─────────────────────────────────────────────────────────

it('counts stock: start, enter, submit, approve corrects the difference', function () {
    invLogin();
    app(StockLedger::class)->record($this->coke, StockMovementType::Opening, 30, 100);

    $this->post(route('stock-counts.store'), ['kind' => 'all'])->assertSessionHasNoErrors();
    $count = StockCount::query()->sole();
    $lines = $count->items()->get()->keyBy('item_name');
    expect($lines)->toHaveCount(2)->and($lines['Chicken']->system_qty)->toBe('10.000');

    // a sale after the count started does not change the correction
    app(StockLedger::class)->record($this->coke, StockMovementType::Sale, -2);

    $this->put(route('stock-counts.update', $count), ['counted' => [$lines['Chicken']->uuid => 9.2, $lines['Coke']->uuid => 30]])->assertSessionHasNoErrors();
    $this->put(route('stock-counts.approve', $count))->assertSessionHasErrors('count'); // not submitted yet
    $this->put(route('stock-counts.update', $count), ['counted' => [$lines['Chicken']->uuid => 9.2], 'submit' => true])->assertSessionHasNoErrors();
    expect($count->fresh()->status)->toBe(StockCountStatus::Submitted);

    $response = $this->get(route('stock-counts.show', $count))->assertInertia(fn (Assert $page) => $page
        ->component('stock-counts/Show')->where('count.items.0.variance', -0.8));
    expectNoNumericIds($response->inertiaProps());

    $this->put(route('stock-counts.approve', $count))->assertSessionHasNoErrors();
    expect($count->fresh())->status->toBe(StockCountStatus::Approved)->variance_value->toBe('-640.00');
    expect($this->chicken->fresh()->current_stock)->toBe('9.200');
    expect($this->coke->fresh()->current_stock)->toBe('28.000'); // counted = system: no correction
    expect(StockMovement::query()->where('type', StockMovementType::CountCorrection)->count())->toBe(1);

    $this->put(route('stock-counts.cancel', $count))->assertSessionHasErrors('count');
});

it('lets only managers approve counts', function () {
    invLogin(array_values(array_diff(INV_ROUTES, ['stock-counts.approve'])));
    $this->post(route('stock-counts.store'), ['kind' => 'raw_material'])->assertSessionHasNoErrors();
    $this->put(route('stock-counts.approve', StockCount::query()->sole()))->assertForbidden();
});

// ── Consumption review ───────────────────────────────────────────────────

function invOrderLine(): OrderItem
{
    $test = test();
    $zinger = MenuItem::factory()->forBranch($test->home)->create(['name' => 'Zinger', 'price' => 600]);
    $zinger->recipeItems()->create(['raw_material_id' => $test->chicken->id, 'quantity' => 150, 'unit_id' => $test->g->id]);
    $order = Order::factory()->create(['branch_id' => $test->home->id, 'status' => OrderStatus::Placed]);

    return $order->items()->create([
        'sellable_type' => 'menu_item', 'sellable_id' => $zinger->id, 'item_name' => 'Zinger', 'quantity' => 2,
        'unit_price' => 600, 'line_total' => 1200, 'consumption_status' => ConsumptionStatus::Pending,
        'sent_by' => Admin::factory()->create()->id, 'sent_at' => now(),
    ]);
}

it('confirms pending consumption from the review list, and corrects it later', function () {
    invLogin();
    $line = invOrderLine();

    $response = $this->get(route('consumptions.pending'))->assertInertia(fn (Assert $page) => $page
        ->component('consumptions/Index')->where('pendingCount', 1)->where('pending.0.materials.0.expected', 300));
    expectNoNumericIds($response->inertiaProps());

    $this->post(route('consumptions.confirm'), ['items' => [$line->uuid]])->assertSessionHasNoErrors();
    expect($line->fresh()->consumption_status)->toBe(ConsumptionStatus::Confirmed);
    expect($this->chicken->fresh()->current_stock)->toBe('9.700');

    // bigger fillets: 360 g used — a correction row, the expected total stays
    $materials = [['id' => $this->chicken->uuid, 'quantity' => 360, 'reason' => null]];
    $this->put(route('consumptions.adjust', $line), ['consumption' => [['item' => $line->uuid, 'materials' => $materials]]])
        ->assertSessionHasErrors(['consumption' => 'Say why Chicken changes.']);
    $materials[0]['reason'] = 'Bigger fillets';
    $this->put(route('consumptions.adjust', $line), ['consumption' => [['item' => $line->uuid, 'materials' => $materials]]])->assertSessionHasNoErrors();

    expect($this->chicken->fresh()->current_stock)->toBe('9.640');
    $rows = OrderItemConsumption::query()->where('order_item_id', $line->id)->get();
    expect($rows)->toHaveCount(2)
        ->and((float) $rows->sum('expected_qty'))->toBe(300.0)
        ->and((float) $rows->sum('actual_qty'))->toBe(360.0);
});

it('auto-confirms unconfirmed consumption at shift close or order completion (setting)', function () {
    $admin = invLogin([...INV_ROUTES, 'shifts.close']);
    $shift = invShift($admin);
    invSetting('shifts', 'require_denominations', false);
    $line = invOrderLine();
    $line->update(['kitchen_status' => 'served']);

    invSetting('inventory', 'auto_confirm_consumption', 'never');
    $this->put(route('shifts.close', $shift), ['counted_cash' => 5000, 'float_left' => 0])->assertSessionHasNoErrors();
    expect($line->fresh()->consumption_status)->toBe(ConsumptionStatus::Pending);

    invSetting('inventory', 'auto_confirm_consumption', 'shift_close');
    $next = invShift($admin);
    $this->put(route('shifts.close', $next), ['counted_cash' => 5000, 'float_left' => 0])->assertSessionHasNoErrors();
    expect($line->fresh()->consumption_status)->toBe(ConsumptionStatus::AutoConfirmed);
    expect($this->chicken->fresh()->current_stock)->toBe('9.700');

    $this->get(route('consumptions.pending', ['view' => 'auto']))->assertInertia(fn (Assert $page) => $page
        ->where('pendingCount', 0)->where('auto.0.materials.0.used', 300));
});

// ── Low stock ────────────────────────────────────────────────────────────

it('lists low stock items, most urgent first', function () {
    invLogin();
    app(StockLedger::class)->record($this->chicken, StockMovementType::Consumption, -6); // 4 kg left, alert at 5
    $this->coke->update(['alert_level' => 12]); // 0 in stock

    $response = $this->get(route('low-stock.index'))->assertInertia(fn (Assert $page) => $page
        ->component('low-stock/Index')
        ->has('items', 2)
        ->where('items.0.name', 'Coke')
        ->where('items.0.out', true)
        ->where('items.1.short_text', '1 kg'));
    expectNoNumericIds($response->inertiaProps());
});
