<?php

use App\Enums\ConsumptionStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\PrintDocument;
use App\Enums\PrintJobStatus;
use App\Enums\StockMovementType;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItemConsumption;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\RawMaterial;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Support\CurrentBranch;
use App\Support\Printing\EscPos;
use App\Support\Settings\SettingsResolver;
use App\Support\StockLedger;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Kitchen (PLAN §4.12 / §4.16): KDS board, start → ready (cook-confirmed raw materials,
 * stock deducted) → served, recall, order status following the kitchen, KOT and void
 * slip print jobs, the print agent endpoints and the polled change stamps.
 */

const KITCHEN_ROUTES = ['kitchen.index', 'kitchen.tickets.start', 'kitchen.tickets.ready', 'kitchen.tickets.serve', 'kitchen.tickets.recall', 'kitchen.tickets.reprint'];
const PRINT_ROUTES = ['print-jobs.pending', 'print-jobs.claim', 'print-jobs.show', 'print-jobs.done', 'print-jobs.failed'];

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];

    $this->g = Unit::query()->where('short_name', 'g')->sole();
    $this->printer = Printer::factory()->forBranch($this->home)->kitchen()->network()->create(['name' => 'Grill printer']);
    $this->grill = KitchenStation::factory()->forBranch($this->home)->create(['name' => 'Grill', 'printer_id' => $this->printer->id]);
    $this->fryer = KitchenStation::factory()->forBranch($this->home)->create(['name' => 'Fryer']);
    $burgers = Category::factory()->forBranch($this->home)->create(['name' => 'Burgers', 'kitchen_station_id' => $this->grill->id]);

    // chicken kept in kg, the recipe in g
    $this->chicken = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Chicken']);
    $this->bun = RawMaterial::factory()->forBranch($this->home)->unit('pcs')->create(['name' => 'Bun']);
    $this->cheese = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Cheese']);
    app(StockLedger::class)->record($this->chicken, StockMovementType::StockIn, 10, 900);
    app(StockLedger::class)->record($this->bun, StockMovementType::StockIn, 50, 40);
    app(StockLedger::class)->record($this->cheese, StockMovementType::StockIn, 2, 2000);

    $this->zinger = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Zinger', 'price' => 600, 'category_id' => $burgers->id]);
    $this->zinger->recipeItems()->create(['raw_material_id' => $this->chicken->id, 'quantity' => 150, 'unit_id' => $this->g->id]);
    $this->zinger->recipeItems()->create(['raw_material_id' => $this->bun->id, 'quantity' => 1, 'unit_id' => $this->bun->stock_unit_id]);

    $extras = ModifierGroup::factory()->forBranch($this->home)->create(['name' => 'Extras']);
    $this->extraCheese = $extras->modifiers()->create(['name' => 'Extra cheese', 'price' => 100]);
    $this->extraCheese->recipeItems()->create(['raw_material_id' => $this->cheese->id, 'quantity' => 30, 'unit_id' => $this->g->id]);
    $this->zinger->modifierGroups()->attach($extras->id);

    $this->fries = MenuItem::factory()->forBranch($this->home)->create(['name' => 'Fries', 'price' => 250, 'category_id' => $burgers->id, 'kitchen_station_id' => $this->fryer->id]);
});

function kitchenSetting(Branch $branch, string $group, string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['branch_id' => $branch->id, 'group' => $group, 'key' => $key], ['value' => $value]);
    app(SettingsResolver::class)->forget($branch->id);
}

/** Place an order through the POS as a cashier with an open shift; back to the given admin afterwards. */
function sendOrder(array $items, array $extra = [], ?Admin $then = null): Order
{
    $test = test();
    $cashier = loginAdminWithRoutes(['pos.index', 'pos.orders.store', 'pos.orders.update'], $test->home);
    $counter = CashCounter::factory()->forBranch($test->home)->create();
    Shift::factory()->create(['branch_id' => $test->home->id, 'cash_counter_id' => $counter->id, 'opened_by' => $cashier->id]);

    $test->withSession($test->atHome)->post(route('pos.orders.store'), ['action' => 'send', 'type' => 'takeaway', 'items' => $items, ...$extra])
        ->assertSessionHasNoErrors()->assertRedirect();

    if ($then) {
        test()->actingAs($then, 'admin');
    }

    return Order::query()->latest('id')->firstOrFail();
}

function menuLine(MenuItem $item, int $quantity = 1, array $extra = []): array
{
    return ['type' => 'menu_item', 'id' => $item->uuid, 'quantity' => $quantity, ...$extra];
}

function cook(array $routes = []): Admin
{
    return loginAdminWithRoutes([...KITCHEN_ROUTES, ...$routes], test()->home);
}

it('shows the board per station with timers and rules, without numeric ids', function () {
    $order = sendOrder([menuLine($this->zinger, 2, ['notes' => 'No mayo']), menuLine($this->fries)]);
    cook();

    $response = $this->withSession($this->atHome)->get(route('kitchen.index'))->assertInertia(fn (Assert $page) => $page
        ->component('kitchen/Index')
        ->has('tickets', 2)
        ->where('tickets.0.code', 'KOT-001')
        ->where('tickets.0.station.name', 'Grill')
        ->where('tickets.0.order.code', $order->code())
        ->where('tickets.0.items.0.name', 'Zinger')
        ->where('tickets.0.items.0.quantity', 2)
        ->where('tickets.0.items.0.notes', 'No mayo')
        ->where('tickets.0.items.0.confirm', true)
        ->where('stations.0.name', 'Fryer')
        ->where('stations.1.count', 1)
        ->where('rules.amber_after', 10)
        ->missing('done'));
    expectNoNumericIds($response->inertiaProps());

    $this->withSession($this->atHome)->get(route('kitchen.index', ['station' => $this->fryer->uuid]))
        ->assertInertia(fn (Assert $page) => $page->where('station', $this->fryer->uuid)->has('tickets', 1)->where('tickets.0.items.0.name', 'Fries'));

    // another branch's ticket is not on the board, and can't be touched
    $theirs = KitchenTicket::query()->first();
    loginAdminWithRoutes(KITCHEN_ROUTES, $this->other);
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])->get(route('kitchen.index'))
        ->assertInertia(fn (Assert $page) => $page->has('tickets', 0));
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])->put(route('kitchen.tickets.start', $theirs))->assertNotFound();
});

it('starts, readies with confirmed raw materials, and serves: stock and order follow', function () {
    $order = sendOrder([menuLine($this->zinger, 2, ['modifiers' => [$this->extraCheese->uuid]])]);
    DiningTable::factory()->forBranch($this->home)->create();
    $cook = cook();
    $ticket = KitchenTicket::query()->sole();
    $line = $ticket->items()->sole();

    $this->withSession($this->atHome)->put(route('kitchen.tickets.start', $ticket))->assertSessionHasNoErrors();
    expect($ticket->refresh())->status->toBe(KitchenStatus::Preparing)->started_at->not->toBeNull();
    expect($order->refresh()->status)->toBe(OrderStatus::Preparing);

    // the cook sees the recipe × 2 plus the add-on's recipe
    $this->withSession($this->atHome)->get(route('kitchen.index', ['ticket' => $ticket->uuid]))
        ->assertInertia(fn (Assert $page) => $page->missing('confirm')->reloadOnly(['confirm', 'materials'], fn (Assert $reload) => $reload
            ->where('confirm.0.id', $line->uuid)
            ->where('confirm.0.materials', [
                ['id' => $this->chicken->uuid, 'name' => 'Chicken', 'unit' => 'g', 'expected' => 300, 'expected_text' => '300 g'],
                ['id' => $this->bun->uuid, 'name' => 'Bun', 'unit' => 'pcs', 'expected' => 2, 'expected_text' => '2 pcs'],
                ['id' => $this->cheese->uuid, 'name' => 'Cheese', 'unit' => 'g', 'expected' => 60, 'expected_text' => '60 g'],
            ])
            ->where('materials.0.name', 'Bun')));

    // "confirm raw materials" is on: bumping without them is refused
    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', $ticket))
        ->assertSessionHasErrors(['consumption' => 'Confirm the raw materials used for 2 × Zinger.']);

    // bigger fillets (340 g), and 10 g of mayo that isn't in the recipe
    $mayo = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Mayo']);
    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', $ticket), ['consumption' => [[
        'item' => $line->uuid,
        'materials' => [
            ['id' => $this->chicken->uuid, 'quantity' => 340, 'reason' => 'Bigger fillets'],
            ['id' => $this->bun->uuid, 'quantity' => 2],
            ['id' => $this->cheese->uuid, 'quantity' => 60],
            ['id' => $mayo->uuid, 'quantity' => 0.01],
        ],
    ]]])->assertSessionHasNoErrors();

    expect($line->refresh())
        ->kitchen_status->toBe(KitchenStatus::Ready)
        ->consumption_status->toBe(ConsumptionStatus::Confirmed)
        ->ready_at->not->toBeNull();
    expect($ticket->refresh())->status->toBe(KitchenStatus::Ready)->completed_at->not->toBeNull();
    expect($order->refresh()->status)->toBe(OrderStatus::Ready);

    expect((float) $this->chicken->refresh()->current_stock)->toBe(9.66)   // 10 kg − 340 g
        ->and((float) $this->bun->refresh()->current_stock)->toBe(48.0)
        ->and((float) $this->cheese->refresh()->current_stock)->toBe(1.94)
        ->and((float) $mayo->refresh()->current_stock)->toBe(-0.01);        // the food is made — stock may go negative

    $chicken = OrderItemConsumption::query()->where('raw_material_id', $this->chicken->id)->sole();
    expect($chicken)
        ->expected_qty->toBe('300.000')->actual_qty->toBe('340.000')->unit_id->toBe($this->g->id)
        ->reason->toBe('Bigger fillets')->auto->toBeFalse()->confirmed_by->toBe($cook->id);
    expect($chicken->stockMovement)->type->toBe(StockMovementType::Consumption)->quantity->toBe('-0.340')->unit_cost->toBe('900.0000');
    expect(OrderItemConsumption::query()->where('raw_material_id', $mayo->id)->sole())->expected_qty->toBe('0.000');
    expect(OrderItemConsumption::query()->where('raw_material_id', $this->bun->id)->sole()->reason)->toBeNull();

    // served → gone from the board; takeaway stays ready until it is collected & paid
    $this->withSession($this->atHome)->put(route('kitchen.tickets.serve', $ticket))->assertSessionHasNoErrors();
    expect($ticket->refresh())->status->toBe(KitchenStatus::Served)->served_at->not->toBeNull();
    expect($line->refresh()->kitchen_status)->toBe(KitchenStatus::Served);
    expect($order->refresh()->status)->toBe(OrderStatus::Ready);
    $this->withSession($this->atHome)->get(route('kitchen.index'))->assertInertia(fn (Assert $page) => $page->has('tickets', 0));

    // recall: served → ready → preparing; stock is not deducted twice
    $this->withSession($this->atHome)->put(route('kitchen.tickets.recall', $ticket))->assertSessionHas('success', 'KOT-001 recalled.');
    expect($ticket->refresh()->status)->toBe(KitchenStatus::Ready);
    $this->withSession($this->atHome)->put(route('kitchen.tickets.recall', $ticket));
    expect($ticket->refresh())->status->toBe(KitchenStatus::Preparing)->completed_at->toBeNull();
    expect($order->refresh()->status)->toBe(OrderStatus::Preparing);
    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', $ticket))->assertSessionHasNoErrors();
    expect(StockMovement::query()->where('type', StockMovementType::Consumption)->count())->toBe(4);
});

it('auto-confirms at recipe quantities when confirming is off, one line at a time', function () {
    kitchenSetting($this->home, 'kitchen', 'confirm_consumption', false);
    $order = sendOrder([menuLine($this->zinger), menuLine($this->zinger, 1, ['notes' => 'Spicy'])]);
    cook();
    $ticket = KitchenTicket::query()->sole();
    [$first, $second] = $ticket->items()->get()->all();

    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', $ticket), ['items' => [$first->uuid]])->assertSessionHasNoErrors();
    expect($first->refresh())->kitchen_status->toBe(KitchenStatus::Ready)->consumption_status->toBe(ConsumptionStatus::AutoConfirmed);
    expect($second->refresh()->kitchen_status)->toBe(KitchenStatus::Pending);
    expect($ticket->refresh()->status)->toBe(KitchenStatus::Preparing);
    expect($order->refresh()->status)->toBe(OrderStatus::Preparing);
    expect((float) $this->chicken->refresh()->current_stock)->toBe(9.85);
    expect(OrderItemConsumption::query()->where('order_item_id', $first->id)->get())
        ->toHaveCount(2)
        ->each(fn ($c) => $c->auto->toBeTrue()->confirmed_by->toBeNull());

    // lines of another order's ticket are refused
    $fries = sendOrder([menuLine($this->fries)], then: cook());
    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', $ticket), ['items' => [$fries->items()->sole()->uuid]])
        ->assertSessionHasErrors(['items' => 'Some of these items are not on this ticket.']);

    // the rest of the ticket → order ready
    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', $ticket))->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(OrderStatus::Ready);
    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', $ticket))
        ->assertSessionHasErrors(['ticket' => 'Nothing left to make on KOT-001.']);
});

it('serves dine-in orders, and voiding the last unfinished line makes the order ready', function () {
    kitchenSetting($this->home, 'kitchen', 'confirm_consumption', false);
    $table = DiningTable::factory()->forBranch($this->home)->create(['name' => 'T5']);
    $order = sendOrder([menuLine($this->zinger), menuLine($this->fries)], ['type' => 'dine_in', 'table' => $table->uuid, 'guests' => 2]);
    $admin = cook(['orders.items.void']);
    kitchenSetting($this->home, 'approvals', 'pin_void', false);
    [$grillTicket, $fryerTicket] = KitchenTicket::query()->orderBy('id')->get()->all();

    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', $grillTicket))->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(OrderStatus::Preparing);   // the fries are not ready yet

    // the fries are voided before they are made: no raw materials, a void slip… no printer on the fryer
    $fries = $fryerTicket->items()->sole();
    $this->withSession($this->atHome)->put(route('orders.items.void', [$order, $fries]), ['quantity' => 1, 'reason' => 'Customer changed mind'])
        ->assertSessionHasNoErrors();
    expect($fries->refresh()->consumption_status)->toBe(ConsumptionStatus::NotRequired);
    expect($order->refresh()->status)->toBe(OrderStatus::Ready);
    $this->withSession($this->atHome)->get(route('kitchen.index'))
        ->assertInertia(fn (Assert $page) => $page->has('tickets', 1)->where('tickets.0.code', 'KOT-001'));

    $this->withSession($this->atHome)->put(route('kitchen.tickets.serve', $grillTicket))->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(OrderStatus::Served);
    expect($order->histories()->pluck('to_status')->map->value->all())->toBe(['placed', 'preparing', 'ready', 'served']);
});

it('queues kitchen tickets and void slips for the station printer', function () {
    kitchenSetting($this->home, 'printing', 'kot_copies', 2);
    kitchenSetting($this->home, 'approvals', 'pin_void', false);
    $order = sendOrder([menuLine($this->zinger, 3), menuLine($this->fries)]);

    // only the grill has a printer
    $kot = PrintJob::query()->sole();
    expect($kot)
        ->document_type->toBe(PrintDocument::Kot)
        ->printer_id->toBe($this->printer->id)
        ->title->toBe('KOT-001 · '.$order->code())
        ->copies->toBe(2)
        ->status->toBe(PrintJobStatus::Pending)
        ->reference->toBeInstanceOf(KitchenTicket::class);

    // partial void → slip for the voided quantity
    loginAdminWithRoutes(['orders.items.void'], $this->home);
    $zinger = $order->items()->where('item_name', 'Zinger')->sole();
    $this->withSession($this->atHome)->put(route('orders.items.void', [$order, $zinger]), ['quantity' => 1, 'reason' => 'Wrong item'])
        ->assertSessionHasNoErrors();
    $slip = PrintJob::query()->where('document_type', PrintDocument::Void)->sole();
    expect($slip)->title->toBe('VOID 1 × Zinger · KOT-001')->copies->toBe(1);

    // auto-print off → nothing queued on send; reprint still works
    kitchenSetting($this->home, 'printing', 'auto_print_kot', false);
    sendOrder([menuLine($this->zinger)], then: cook());
    expect(PrintJob::query()->count())->toBe(2);
    $ticket = KitchenTicket::query()->latest('id')->first();
    $this->withSession($this->atHome)->post(route('kitchen.tickets.reprint', $ticket))
        ->assertSessionHas('success', "{$ticket->code()} sent to Grill printer.");
    expect(PrintJob::query()->latest('id')->first())->title->toEndWith('(reprint)')->copies->toBe(1);

    // a station without a printer can't reprint
    $fryer = KitchenTicket::query()->where('kitchen_station_id', $this->fryer->id)->first();
    $this->withSession($this->atHome)->post(route('kitchen.tickets.reprint', $fryer))
        ->assertSessionHas('error', 'KOT-002 has no kitchen printer — set one on the kitchen station.');
});

it('lets a print device claim, print and report jobs; a job is claimed once', function () {
    sendOrder([menuLine($this->zinger, 2, ['notes' => 'No mayo'])]);
    $job = PrintJob::query()->sole();
    $device = loginAdminWithRoutes(PRINT_ROUTES, $this->home);

    $this->withSession($this->atHome)->getJson(route('print-jobs.pending', ['printers' => [$this->printer->uuid]]))
        ->assertOk()->assertJsonPath('jobs.0.id', $job->uuid);
    $this->withSession($this->atHome)->getJson(route('print-jobs.pending', ['printers' => [Printer::factory()->forBranch($this->home)->create()->uuid]]))
        ->assertJsonCount(0, 'jobs');

    $claim = $this->withSession($this->atHome)->postJson(route('print-jobs.claim', $job))->assertOk()
        ->assertJsonPath('printer.connection', 'network')
        ->assertJsonPath('printer.host', '192.168.1.50')
        ->assertJsonPath('printer.port', 9100)
        ->assertJsonPath('page', route('print-jobs.show', $job));
    $bytes = base64_decode($claim->json('escpos'));
    expect($bytes)->toStartWith("\x1B@")->toContain('KOT-001')->toContain('2 x Zinger')->toContain('** No mayo')->toContain('GRILL');
    expect($job->refresh())->status->toBe(PrintJobStatus::Printing)->attempts->toBe(1);

    $this->withSession($this->atHome)->postJson(route('print-jobs.claim', $job))->assertStatus(409);

    $this->withSession($this->atHome)->get(route('print-jobs.show', $job))->assertOk()->assertSee('KOT-001')->assertSee('No mayo');

    $this->withSession($this->atHome)->postJson(route('print-jobs.failed', $job), ['error' => 'Printer offline'])->assertOk();
    expect($job->refresh())->status->toBe(PrintJobStatus::Failed)->error->toBe('Printer offline');

    // retry from the print queue, then printed → the ticket is marked printed
    loginAdminWithRoutes(['print-jobs.index', 'print-jobs.retry', ...PRINT_ROUTES], $this->home);
    $this->withSession($this->atHome)->post(route('print-jobs.retry', $job))->assertSessionHas('success');
    expect($job->refresh()->status)->toBe(PrintJobStatus::Pending);
    $this->withSession($this->atHome)->postJson(route('print-jobs.claim', $job))->assertOk();
    $this->withSession($this->atHome)->postJson(route('print-jobs.done', $job))->assertOk();
    expect($job->refresh())->status->toBe(PrintJobStatus::Printed)->attempts->toBe(2)->printed_at->not->toBeNull();
    expect(KitchenTicket::query()->sole()->printed_at)->not->toBeNull();

    // printing a printed job again makes a new job
    $this->withSession($this->atHome)->post(route('print-jobs.retry', $job))->assertSessionHas('success');
    expect(PrintJob::query()->count())->toBe(2);

    $response = $this->withSession($this->atHome)->get(route('print-jobs.index'))->assertInertia(fn (Assert $page) => $page
        ->component('print-jobs/Index')
        ->has('jobs.data', 2)
        ->where('counts.printed', 1));
    expectNoNumericIds($response->inertiaProps());

    // another branch never sees these jobs
    loginAdminWithRoutes(PRINT_ROUTES, $this->other);
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])->postJson(route('print-jobs.claim', $job))->assertNotFound();
});

it('wraps ESC/POS text at the paper width', function () {
    $bytes = (new EscPos(58))->text(str_repeat('word ', 10))->rule()->toString();

    expect(explode("\n", $bytes))
        ->{2}->toBe(str_repeat('-', 32))
        ->and(max(array_map('strlen', explode("\n", substr($bytes, 2)))))->toBeLessThanOrEqual(32);
    expect(EscPos::ascii('2 × Zinger — Large'))->toBe('2 x Zinger - Large');
});

it('lets screens poll change stamps: kitchen, ready orders with events, printers', function () {
    kitchenSetting($this->home, 'kitchen', 'confirm_consumption', false);
    cook(['pos.index']);

    $first = $this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['kitchen', 'orders', 'printers', 'nope']]))
        ->assertOk()->assertJsonPath('versions.kitchen', 0)->assertJsonPath('events', [])->json();
    expect(array_keys($first['versions']))->toBe(['kitchen', 'orders', 'printers']);

    $this->travel(1)->seconds();
    $order = sendOrder([menuLine($this->zinger)], then: cook(['pos.index']));
    $after = $this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['kitchen', 'printers'], 'since' => $first['stamp']]))->json();
    expect($after['versions']['kitchen'])->toBeGreaterThan(0)
        ->and($after['versions']['printers'])->toBeGreaterThan(0);   // the grill has a printer

    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', KitchenTicket::query()->sole()))->assertSessionHasNoErrors();
    $this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['orders'], 'since' => $after['stamp']]))
        ->assertJsonPath('events.orders.0.id', $order->uuid)
        ->assertJsonPath('events.orders.0.code', $order->code())
        ->assertJsonPath('events.orders.0.label', 'Takeaway');

    // no POS / orders access → no ready events; another branch has its own stamps
    loginAdminWithRoutes(['kitchen.index'], $this->home);
    $this->withSession($this->atHome)->getJson(route('live.poll', ['topics' => ['orders'], 'since' => 0]))->assertJsonPath('versions', []);
    loginAdminWithRoutes(['pos.index'], $this->other);
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])->getJson(route('live.poll', ['topics' => ['kitchen', 'orders'], 'since' => 0]))
        ->assertJsonPath('versions.kitchen', 0)->assertJsonPath('events', []);
});

it('never deletes consumptions or print jobs', function () {
    kitchenSetting($this->home, 'kitchen', 'confirm_consumption', false);
    sendOrder([menuLine($this->zinger)], then: cook());
    $this->withSession($this->atHome)->put(route('kitchen.tickets.ready', KitchenTicket::query()->sole()));

    $consumption = OrderItemConsumption::query()->first();
    expect(fn () => $consumption->update(['actual_qty' => 1]))->toThrow(PermanentDeleteNotAllowed::class);
    expect(fn () => $consumption->delete())->toThrow(PermanentDeleteNotAllowed::class);
    expect(fn () => PrintJob::query()->first()->delete())->toThrow(PermanentDeleteNotAllowed::class);
});
