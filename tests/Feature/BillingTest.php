<?php

use App\Actions\MarkKitchenReady;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PrintDocument;
use App\Enums\StockMovementType;
use App\Enums\TableStatus;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\ReadyItem;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Support\CurrentBranch;
use App\Support\LiveUpdates;
use App\Support\Printing\BillSlip;
use App\Support\Settings\SettingsResolver;
use App\Support\ShiftSummary;
use App\Support\StockLedger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Billing (PLAN §4.13): cash with change, bank transfer, split payment, part payments,
 * split bill (equally / by items), pre-bill and receipt printing, refunds, the shift
 * drawer and the payments list.
 */

const BILLING_ROUTES = [
    'pos.index', 'pos.orders.store', 'pos.orders.update', 'orders.index', 'orders.show',
    'orders.payments.store', 'orders.split', 'orders.print.bill', 'orders.print.receipt', 'orders.bill', 'payments.index',
];

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

    $this->receiptPrinter = Printer::factory()->forBranch($this->home)->create(['name' => 'Counter printer']);
    $this->counter = CashCounter::factory()->forBranch($this->home)->create(['name' => 'Counter 1']);
    $this->bank = BankAccount::factory()->forBranches($this->home)->create(['bank_name' => 'HBL', 'account_title' => 'Gulberg Foods']);
});

function billSetting(string $group, string $key, mixed $value): void
{
    $branch = test()->home;
    Setting::query()->updateOrCreate(['branch_id' => $branch->id, 'group' => $group, 'key' => $key], ['value' => $value]);
    app(SettingsResolver::class)->forget($branch->id);
}

/** A cashier with an open shift (opening cash 5,000) on Counter 1. */
function billCashier(array $routes = []): Admin
{
    $test = test();
    $admin = loginAdminWithRoutes([...BILLING_ROUTES, ...$routes], $test->home);
    $test->shift = Shift::factory()->create(['branch_id' => $test->home->id, 'cash_counter_id' => $test->counter->id, 'opened_by' => $admin->id]);

    return $admin;
}

/** Send a takeaway (or other type) order through the POS. */
function billOrder(array $items, array $extra = []): Order
{
    test()->withSession(test()->atHome)
        ->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => $items, ...$extra])
        ->assertSessionHasNoErrors();

    return Order::query()->latest('id')->firstOrFail();
}

function coke(int $quantity = 1): array
{
    return ['type' => 'ready_item', 'id' => test()->coke->uuid, 'quantity' => $quantity];
}

function zinger(int $quantity = 1): array
{
    return ['type' => 'menu_item', 'id' => test()->zinger->uuid, 'quantity' => $quantity];
}

function pay(Order $order, array $tenders, array $extra = [])
{
    return test()->withSession(test()->atHome)->post(route('orders.payments.store', $order), ['tenders' => $tenders, ...$extra]);
}

it('takes cash with change: into the shift drawer, order complete, receipt printed at the counter', function () {
    billCashier();
    $this->counter->update(['receipt_printer_id' => $this->receiptPrinter->id]);
    $order = billOrder([coke(3)]); // ready items only — nothing to cook

    pay($order, [['method' => 'cash', 'amount' => 450, 'tendered' => 1000]], ['return' => 'pos'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('pos.index'))
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'give back Rs 550 change') && str_contains($m, 'is complete') && str_contains($m, 'Receipt sent to Counter printer'));

    $payment = Payment::query()->sole();
    expect($payment)
        ->method->toBe(PaymentMethod::Cash)
        ->amount->toBe('450.00')
        ->tendered->toBe('1000.00')
        ->change_given->toBe('550.00')
        ->shift_id->toBe($this->shift->id)
        ->business_date->toDateString()->toBe($this->shift->business_date->toDateString());

    expect($order->refresh())
        ->status->toBe(OrderStatus::Completed)
        ->payment_status->toBe(PaymentStatus::Paid)
        ->paid_total->toBe('450.00')
        ->shift_id->toBe($this->shift->id)
        ->completed_at->not->toBeNull();

    // the drawer holds the bill amount, not the note handed over
    expect(ShiftSummary::of($this->shift)->expectedCash())->toBe(5450.0);

    $job = PrintJob::query()->sole();
    expect($job)->document_type->toBe(PrintDocument::Receipt)->printer_id->toBe($this->receiptPrinter->id)->title->toBe("Receipt · {$order->code()}");

    $slip = BillSlip::forJob($job);
    expect($slip['heading'])->toBe('RECEIPT')
        ->and($slip['lines'][0])->toMatchArray(['quantity' => 3, 'name' => 'Coke', 'amount' => '450.00'])
        ->and($slip['payments'])->toContain(['Cash', 'Rs 450'], ['  Cash received', 'Rs 1,000'], ['  Change', 'Rs 550'])
        ->and(BillSlip::escPos($slip, 80))->toContain('RECEIPT')->toContain('3 x Coke');
});

it('prints in the browser when the counter has no receipt printer', function () {
    billCashier();
    $order = billOrder([coke()]);

    pay($order, [['method' => 'cash', 'amount' => 150]])
        ->assertSessionHas('print', fn ($p) => str_contains($p['url'], "/orders/{$order->uuid}/bill") && str_contains($p['url'], 'kind=receipt'));
    expect(PrintJob::query()->count())->toBe(0);

    $this->withSession($this->atHome)->get(route('orders.bill', ['order' => $order, 'kind' => 'receipt', 'auto' => 1]))
        ->assertOk()->assertSee('RECEIPT')->assertSee('1 × Coke', false)->assertSee('Paid in full')->assertSee('window.print()', false);
});

it('takes a split payment: part cash, part bank transfer with reference and screenshot', function () {
    Storage::fake('public');
    billCashier();
    billSetting('payments', 'transfer_proof_required', true);
    $order = billOrder([coke(4)]); // 600

    // reference + screenshot required, bank of this branch only
    $theirs = BankAccount::factory()->forBranches($this->other)->create();
    pay($order, [['method' => 'bank_transfer', 'amount' => 400, 'bank' => $theirs->uuid]])
        ->assertSessionHasErrors(['tenders.0.bank', 'tenders.0.reference', 'tenders.0.proof']);

    pay($order, [
        ['method' => 'cash', 'amount' => 200, 'tendered' => 200],
        ['method' => 'bank_transfer', 'amount' => 400, 'bank' => $this->bank->uuid, 'reference' => 'TRX-991', 'proof' => UploadedFile::fake()->image('slip.jpg')],
    ])->assertSessionHasNoErrors();

    $transfer = Payment::query()->where('method', PaymentMethod::BankTransfer)->sole();
    expect($transfer)->bank_account_id->toBe($this->bank->id)->reference_no->toBe('TRX-991');
    Storage::disk('public')->assertExists($transfer->proof_image);
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);

    // only the cash is in the drawer; transfers are listed per account
    $summary = ShiftSummary::of($this->shift);
    expect($summary->expectedCash())->toBe(5200.0)
        ->and($summary->transfers())->toBe([['account' => 'HBL — Gulberg Foods', 'total' => 400.0, 'count' => 1]]);
});

it('takes part payments and refuses more than is due, closed shifts and held orders', function () {
    billCashier();
    $order = billOrder([coke(4)]); // 600

    pay($order, [['method' => 'bank_transfer', 'amount' => 700, 'bank' => $this->bank->uuid, 'reference' => 'X']])
        ->assertSessionHasErrors(['payment' => 'That is more than the Rs 600 due.']);

    pay($order, [['method' => 'cash', 'amount' => 250]])->assertSessionHasNoErrors();
    expect($order->refresh())->isOpen()->toBeTrue()->payment_status->toBe(PaymentStatus::Partial)->due()->toBe(350.0);

    pay($order, [['method' => 'cash', 'amount' => 350, 'tendered' => 500]])->assertSessionHasNoErrors();
    expect($order->refresh())->status->toBe(OrderStatus::Completed)->payment_status->toBe(PaymentStatus::Paid);
    pay($order, [['method' => 'cash', 'amount' => 10]])->assertSessionHasErrors('payment');

    // a held order is paid after it is sent
    $this->withSession($this->atHome)->post(route('pos.orders.store'), ['action' => 'hold', 'type' => 'takeaway', 'items' => [coke()]]);
    pay(Order::query()->latest('id')->first(), [['method' => 'cash', 'amount' => 150]])
        ->assertSessionHasErrors(['payment' => 'Send the order before taking payment.']);

    // no open shift, no payments
    $next = billOrder([coke()]);
    $this->shift->update(['status' => 'closed', 'closed_at' => now()]);
    pay($next, [['method' => 'cash', 'amount' => 150]])->assertSessionHasErrors(['payment' => 'Open your shift on a cash counter to take payments.']);
});

it('keeps a paid takeaway open until the kitchen is done, then completes it with the ready alert', function () {
    $cashier = billCashier();
    $order = billOrder([zinger()]);
    pay($order, [['method' => 'cash', 'amount' => 600]])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'completes when the kitchen is done'));

    expect($order->refresh())->status->toBe(OrderStatus::Placed)->payment_status->toBe(PaymentStatus::Paid);

    billSetting('kitchen', 'confirm_consumption', false);
    app(MarkKitchenReady::class)->handle(KitchenTicket::query()->sole(), null, [], $cashier);

    expect($order->refresh())->status->toBe(OrderStatus::Completed)
        ->and($order->items()->sole()->kitchen_status)->toBe(KitchenStatus::Ready)
        ->and(LiveUpdates::poll($this->home->id, ['orders'], 0)['events']['orders'][0]['code'])->toBe($order->code());
});

it('frees the dine-in table when the bill is paid, as the settings say', function () {
    billCashier();
    billSetting('orders', 'table_after_payment', 'cleaning');
    $table = DiningTable::factory()->forBranch($this->home)->create();
    $order = billOrder([coke(2)], ['type' => 'dine_in', 'table' => $table->uuid]);
    expect($table->refresh()->status)->toBe(TableStatus::Occupied);

    pay($order, [['method' => 'cash', 'amount' => (float) $order->grand_total]])->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($table->refresh()->status)->toBe(TableStatus::Cleaning);
});

it('splits the bill equally, pays part by part, and locks the split once money is in', function () {
    billCashier();
    $order = billOrder([coke(2), zinger()]);
    $order->forceFill(['grand_total' => 1000])->save(); // a total that doesn't divide by 3

    $this->withSession($this->atHome)->put(route('orders.split', $order), ['mode' => 'equal', 'parts' => 3])->assertSessionHasNoErrors();
    $splits = $order->splits()->get();
    expect($splits->pluck('amount')->all())->toBe(['333.33', '333.33', '333.34'])
        ->and($splits->pluck('label')->all())->toBe(['Guest 1', 'Guest 2', 'Guest 3']);

    // a part pays its share only
    pay($order, [['method' => 'cash', 'amount' => 400]], ['split' => $splits[0]->uuid])->assertSessionHasErrors('payment');
    pay($order, [['method' => 'cash', 'amount' => 333.33, 'tendered' => 500]], ['split' => $splits[0]->uuid])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Guest 1 has paid'));
    expect($splits[0]->refresh()->due())->toBe(0.0)
        ->and(Payment::query()->sole()->bill_split_id)->toBe($splits[0]->id);

    $this->withSession($this->atHome)->put(route('orders.split', $order), ['mode' => 'equal', 'parts' => 2])
        ->assertSessionHasErrors(['split' => 'Money was already taken — the split can’t change now.']);

    // the order page shows the parts without numeric ids
    $response = $this->withSession($this->atHome)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->has('order.splits', 3)
        ->where('order.splits.0.due', 0)
        ->where('order.splits.1.due', 333.33)
        ->where('order.payments.0.split.label', 'Guest 1'));
    expectNoNumericIds($response->inertiaProps());
});

it('splits by items: each guest pays their items’ share of the whole bill', function () {
    billCashier();
    billSetting('tax', 'enabled', true); // 16% on the whole bill
    $order = billOrder([zinger(2), coke(2)]); // 1500 + 16% = 1740
    $zingers = $order->lines()->where('sellable_type', 'menu_item')->sole();
    $cokes = $order->lines()->where('sellable_type', 'ready_item')->sole();

    // every item must be given out
    $this->withSession($this->atHome)->put(route('orders.split', $order), ['mode' => 'items', 'parts' => 2, 'assignments' => [
        [['item' => $zingers->uuid, 'quantity' => 1]],
        [['item' => $cokes->uuid, 'quantity' => 2]],
    ]])->assertSessionHasErrors(['split' => 'Give all 2 × Zinger to the guests.']);

    $this->withSession($this->atHome)->put(route('orders.split', $order), ['mode' => 'items', 'parts' => 2, 'assignments' => [
        [['item' => $zingers->uuid, 'quantity' => 1], ['item' => $cokes->uuid, 'quantity' => 1]], // 750 of 1500
        [['item' => $zingers->uuid, 'quantity' => 1], ['item' => $cokes->uuid, 'quantity' => 1]],
    ]])->assertSessionHasNoErrors();

    expect($order->splits()->pluck('amount')->all())->toBe(['870.00', '870.00'])
        ->and($order->refresh()->split_mode)->toBe('items');

    // the bill of guest 2 lists only their items, with their share
    $this->withSession($this->atHome)->get(route('orders.bill', ['order' => $order, 'split' => $order->splits()->where('number', 2)->first()->uuid]))
        ->assertOk()->assertSee('Guest 2 of 2')->assertSee('Guest 2 pays')->assertSee('1 × Zinger', false);
});

it('drops unpaid parts when the bill changes after splitting', function () {
    billCashier();
    $order = billOrder([coke(2)]);
    $this->withSession($this->atHome)->put(route('orders.split', $order), ['mode' => 'equal', 'parts' => 2]);
    expect($order->splits()->count())->toBe(2);

    $this->withSession($this->atHome)->put(route('pos.orders.update', $order), ['action' => 'send', 'items' => [coke()]])->assertSessionHasNoErrors();

    expect($order->refresh()->split_mode)->toBeNull()
        ->and($order->splits()->count())->toBe(0)
        ->and($order->splits()->onlyTrashed()->count())->toBe(2);
});

it('prints the pre-bill on the counter printer, per part too', function () {
    billCashier(['print-jobs.claim']);
    $this->counter->update(['receipt_printer_id' => $this->receiptPrinter->id]);
    $order = billOrder([coke(2)]);

    $this->withSession($this->atHome)->post(route('orders.print.bill', $order))
        ->assertSessionHas('success', 'Bill sent to Counter printer.');
    $job = PrintJob::query()->sole();
    expect($job)->document_type->toBe(PrintDocument::PreBill)->reference_type->toBe('order');
    expect(BillSlip::forJob($job))->heading->toBe('BILL');

    // claimed by the counter screen as ESC/POS
    $this->withSession($this->atHome)->post(route('print-jobs.claim', $job))->assertOk()
        ->assertJsonPath('title', "Bill · {$order->code()}")
        ->assertJson(fn ($json) => $json->where('escpos', fn ($b) => str_contains(base64_decode($b), 'BILL'))->etc());

    $this->withSession($this->atHome)->put(route('orders.split', $order), ['mode' => 'equal', 'parts' => 2]);
    $part = $order->splits()->first();
    $this->withSession($this->atHome)->post(route('orders.print.bill', $order), ['split' => $part->uuid]);
    expect(PrintJob::query()->latest('id')->first())->reference_type->toBe('bill_split')->title->toBe("Bill · {$order->code()} · Guest 1");

    // no receipt before money
    $this->withSession($this->atHome)->post(route('orders.print.receipt', $order))->assertSessionHas('error');
});

it('refunds cash from the drawer with a manager PIN; nothing kept → refunded', function () {
    billCashier(['orders.payments.refund']);
    $order = billOrder([coke(2)]); // 300
    pay($order, [['method' => 'cash', 'amount' => 300]]);
    $payment = Payment::query()->sole();
    $refund = fn (array $data) => $this->withSession($this->atHome)->post(route('orders.payments.refund', [$order, $payment]), $data);

    // manager PIN (Security & Approvals), then limits
    $refund(['amount' => 100, 'method' => 'cash', 'reason' => 'Cold drink'])->assertSessionHasErrors('pin');
    Admin::factory()->forBranches($this->home)->withRole(Role::factory()->withRoutes('orders.payments.refund')->create())->create(['name' => 'Sana', 'pin' => '4321']);
    $refund(['amount' => 400, 'method' => 'cash', 'reason' => 'x', 'pin' => '4321'])
        ->assertSessionHasErrors(['amount' => 'Only Rs 300 of this payment can be refunded.']);

    $refund(['amount' => 100, 'method' => 'cash', 'reason' => 'Cold drink', 'pin' => '4321'])->assertSessionHasNoErrors();
    expect($order->refresh())
        ->status->toBe(OrderStatus::Completed)
        ->payment_status->toBe(PaymentStatus::PartRefunded)
        ->paid_total->toBe('200.00')
        ->refunded_total->toBe('100.00');
    expect(Refund::query()->sole())->approved_by->not->toBeNull()->shift_id->toBe($this->shift->id);
    expect(ShiftSummary::of($this->shift)->expectedCash())->toBe(5200.0);

    // bank refund of the rest
    $refund(['amount' => 200, 'method' => 'bank_transfer', 'bank' => $this->bank->uuid, 'reason' => 'Order cancelled', 'pin' => '4321'])->assertSessionHasNoErrors();
    expect($order->refresh())->status->toBe(OrderStatus::Refunded)->payment_status->toBe(PaymentStatus::Refunded);
    expect($payment->refresh()->refundable())->toBe(0.0);
});

it('never gives back more cash than the drawer holds', function () {
    billCashier(['orders.payments.refund']);
    billSetting('approvals', 'pin_refund', false);
    $order = billOrder([coke(2)]);
    pay($order, [['method' => 'bank_transfer', 'amount' => 300, 'bank' => $this->bank->uuid, 'reference' => 'R1']]);
    $this->shift->update(['opening_cash' => 50]);

    $this->withSession($this->atHome)->post(route('orders.payments.refund', [$order, Payment::query()->sole()]), ['amount' => 300, 'method' => 'cash', 'reason' => 'x'])
        ->assertSessionHasErrors(['amount' => 'Only Rs 50 is in your drawer.']);
});

it('lists payments and refunds of the branch with totals, without numeric ids', function () {
    billCashier();
    $order = billOrder([coke(2)]);
    pay($order, [['method' => 'cash', 'amount' => 100], ['method' => 'bank_transfer', 'amount' => 200, 'bank' => $this->bank->uuid, 'reference' => 'TRX-7']]);

    $response = $this->withSession($this->atHome)->get(route('payments.index'))->assertInertia(fn (Assert $page) => $page
        ->component('payments/Index')
        ->has('rows.data', 2)
        ->where('stats.cash', 100)
        ->where('stats.bank', 200)
        ->where('stats.net', 300)
        ->where('rows.data.0.order.code', $order->code()));
    expectNoNumericIds($response->inertiaProps());

    $this->withSession($this->atHome)->get(route('payments.index', ['method' => 'bank_transfer']))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.reference_no', 'TRX-7'));

    // another branch sees none of it, and can't pay this order
    loginAdminWithRoutes(BILLING_ROUTES, $this->other);
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])->get(route('payments.index'))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 0)->where('stats.net', 0));
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])->post(route('orders.payments.store', $order), ['tenders' => [['method' => 'cash', 'amount' => 1]]])
        ->assertNotFound();
});

it('never deletes payments or refunds', function () {
    billCashier(['orders.payments.refund']);
    billSetting('approvals', 'pin_refund', false);
    $order = billOrder([coke()]);
    pay($order, [['method' => 'cash', 'amount' => 150]]);
    $this->withSession($this->atHome)->post(route('orders.payments.refund', [$order, Payment::query()->sole()]), ['amount' => 50, 'method' => 'cash', 'reason' => 'x']);

    expect(fn () => Payment::query()->sole()->delete())->toThrow(PermanentDeleteNotAllowed::class)
        ->and(fn () => Refund::query()->sole()->delete())->toThrow(PermanentDeleteNotAllowed::class)
        ->and(fn () => Refund::query()->sole()->update(['amount' => 1]))->toThrow(PermanentDeleteNotAllowed::class);
});
