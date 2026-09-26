<?php

use App\Enums\ConsumptionStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RawMaterial;
use App\Models\Shift;
use App\Reports\ReportRegistry;
use App\Support\CurrentBranch;
use App\Support\StockLedger;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Reports (PLAN §4.19 / Phase 15) and the dashboard figures (polled every minute).
 */

const REPORT_ROUTES = ['reports.index', 'reports.sales', 'reports.cash', 'reports.inventory', 'reports.finance', 'reports.export'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 14:00', 'Asia/Karachi'));
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->hidden = Branch::factory()->create(['name' => 'Bahria']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];
});

function rptLogin(array $routes, Branch ...$branches): Admin
{
    $admin = loginAdminWithRoutes($routes, ...($branches ?: [test()->home]));
    test()->withSession(test()->atHome);

    return $admin;
}

/** A completed, paid order: items 1,000 − discount 100 + tax 144 = 1,044, one line of Zinger ×2. */
function rptOrder(Branch $branch, array $attributes = [], string $method = 'cash'): Order
{
    $zinger = MenuItem::query()->forBranch($branch)->where('name', 'Zinger')->first()
        ?? MenuItem::factory()->forBranch($branch)->create(['name' => 'Zinger', 'price' => 500]);
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'type' => OrderType::DineIn,
        'status' => OrderStatus::Completed,
        'business_date' => '2026-09-26',
        'guests' => 2,
        'items_total' => 1000, 'discount_total' => 100, 'net_total' => 900,
        'tax_name' => 'GST', 'tax_rate' => 16, 'tax_total' => 144, 'grand_total' => 1044, 'paid_total' => 1044,
        ...$attributes,
    ]);
    $admin = Admin::factory()->create();
    $order->items()->create([
        'sellable_type' => 'menu_item', 'sellable_id' => $zinger->id, 'item_name' => 'Zinger', 'quantity' => 2,
        'unit_price' => 500, 'line_total' => 1000, 'consumption_status' => ConsumptionStatus::NotRequired,
        'sent_by' => $admin->id, 'sent_at' => now(),
    ]);
    $shift = Shift::factory()->create(['branch_id' => $branch->id, 'cash_counter_id' => CashCounter::factory()->create(['branch_id' => $branch->id])->id]);
    Payment::create([
        'branch_id' => $branch->id, 'order_id' => $order->id, 'shift_id' => $shift->id, 'business_date' => $order->business_date,
        'method' => PaymentMethod::from($method), 'amount' => $order->grand_total, 'received_by' => $admin->id,
    ]);

    return $order;
}

// ── Reports ──────────────────────────────────────────────────────────────

it('lists only the report groups the role allows', function () {
    rptLogin(['reports.index', 'reports.sales']);

    $this->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/Index')
        ->has('groups', 1)
        ->where('groups.0.key', 'sales'));

    $this->get(route('reports.sales', 'daily-sales'))->assertOk();
    $this->get(route('reports.finance', 'profit-loss'))->assertForbidden();
    $this->get(route('reports.sales', 'no-such-report'))->assertNotFound();
    // a report opened under another group's route is not found
    $this->get(route('reports.sales', 'profit-loss'))->assertNotFound();
    $this->get(route('reports.export', ['group' => 'sales', 'report' => 'daily-sales']))->assertForbidden();
});

it('adds up daily sales, order types and payments by business day', function () {
    rptLogin(REPORT_ROUTES);
    rptOrder($this->home);
    rptOrder($this->home, ['type' => OrderType::Takeaway, 'guests' => null], 'bank_transfer');
    rptOrder($this->home, ['status' => OrderStatus::Cancelled]); // not a sale
    rptOrder($this->home, ['business_date' => '2026-08-01']); // outside the range
    rptOrder($this->other); // another branch

    $response = $this->get(route('reports.sales', ['report' => 'daily-sales', 'from' => '2026-09-01', 'to' => '2026-09-26']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Show')
            ->has('rows', 1)
            ->where('rows.0.date', '2026-09-26')
            ->where('rows.0.orders', 2)
            ->where('rows.0.total', 2088)
            ->where('rows.0.discounts', 200)
            ->where('totals.net', 2088)
            ->where('meta.branch_label', 'Gulberg'));
    expectNoNumericIds($response->inertiaProps());

    $this->get(route('reports.sales', ['report' => 'order-types', 'from' => '2026-09-26', 'to' => '2026-09-26']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('totals.share', 100)->where('totals.average', 1044));

    $this->get(route('reports.sales', ['report' => 'items', 'from' => '2026-09-26', 'to' => '2026-09-26']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.item', 'Zinger')->where('rows.0.qty', 4)->where('rows.0.total', 2000));

    $this->get(route('reports.cash', ['report' => 'payments', 'from' => '2026-09-26', 'to' => '2026-09-26']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('totals.received', 3132)); // cancelled order's payment counts as money received
});

it('covers all allowed branches, never a branch the admin has no access to', function () {
    rptLogin(REPORT_ROUTES, $this->home, $this->other);
    rptOrder($this->home);
    rptOrder($this->other);
    rptOrder($this->hidden);

    $this->get(route('reports.sales', ['report' => 'branches', 'branch' => 'all', 'from' => '2026-09-26', 'to' => '2026-09-26']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 2)
            ->where('totals.orders', 2)
            ->where('filters.branch', 'all')
            ->has('branchOptions', 3));

    // a branch outside my access falls back to my current branch
    $this->get(route('reports.sales', ['report' => 'daily-sales', 'branch' => $this->hidden->uuid, 'from' => '2026-09-26', 'to' => '2026-09-26']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.branch', $this->home->uuid)->where('totals.orders', 1));
});

it('works out profit and loss with cost of goods, waste and expenses', function () {
    rptLogin(REPORT_ROUTES);
    rptOrder($this->home);
    $this->withSession($this->atHome);
    app(CurrentBranch::class)->set($this->home);

    $flour = RawMaterial::factory()->forBranch($this->home)->create(['name' => 'Flour']);
    app(StockLedger::class)->record($flour, StockMovementType::Opening, 10, 100);
    app(StockLedger::class)->record($flour, StockMovementType::Consumption, -2); // costs 2 × 100
    app(StockLedger::class)->record($flour, StockMovementType::Waste, -1);       // 100
    Expense::create([
        'branch_id' => $this->home->id, 'number' => 1, 'expense_category_id' => ExpenseCategory::factory()->create(['name' => 'Gas'])->id,
        'business_date' => '2026-09-26', 'amount' => 300, 'description' => 'Gas bill', 'paid_from' => PaymentMethod::BankTransfer,
        'created_by' => Admin::factory()->create()->id,
    ]);

    $rows = collect($this->get(route('reports.finance', ['report' => 'profit-loss', 'from' => '2026-09-26', 'to' => '2026-09-26']))
        ->assertOk()->inertiaProps('rows'))->keyBy('line');

    expect($rows['Net sales (before tax)']['amount'])->toEqual(900)
        ->and($rows['− Cost of goods (kitchen use + ready items)']['amount'])->toEqual(-200)
        ->and($rows['Gross profit']['amount'])->toEqual(700)
        ->and($rows['− Waste & damage']['amount'])->toEqual(-100)
        ->and($rows['− Expense: Gas']['amount'])->toEqual(-300)
        ->and($rows['Net profit']['amount'])->toEqual(300)
        ->and($rows['Tax collected (not income)']['amount'])->toEqual(144);
});

it('opens every report and exports it to Excel and PDF', function () {
    rptLogin(REPORT_ROUTES);
    rptOrder($this->home);
    Excel::fake();

    foreach (ReportRegistry::all() as $report) {
        $response = $this->get(route("reports.{$report->group()}", $report->key()))->assertOk();
        expectNoNumericIds($response->inertiaProps());
    }

    $this->get(route('reports.export', ['group' => 'sales', 'report' => 'daily-sales', 'format' => 'xlsx']))->assertOk();
    Excel::assertDownloaded('daily-sales-gulberg-2026-09-01-2026-09-26.xlsx');

    $pdf = $this->get(route('reports.export', ['group' => 'finance', 'report' => 'profit-loss', 'format' => 'pdf']))->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('pdf');
});

// ── Dashboard ────────────────────────────────────────────────────────────

it('gives dashboard figures only with the permission, and refreshes them as JSON', function () {
    rptLogin(['dashboard.stats'], $this->home, $this->other);
    rptOrder($this->home);
    rptOrder($this->home, ['business_date' => '2026-09-25', 'grand_total' => 522]);
    rptOrder($this->other);

    $response = $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('stats.sales.total', 1044)
        ->where('stats.sales.orders', 1)
        ->where('stats.sales.change', 100)
        ->where('stats.payments.cash', 1044)
        ->has('stats.week', 7)
        ->where('refreshSeconds', 60)
        ->has('branchOptions', 3));
    expectNoNumericIds($response->inertiaProps());

    $this->getJson(route('dashboard.stats', ['branch' => 'all']))
        ->assertOk()
        ->assertJsonPath('sales.total', 2088)
        ->assertJsonPath('sales.orders', 2)
        ->assertJsonCount(1, 'top_items');

    // without the permission: the page opens, the figures don't
    rptLogin([]);
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats', null));
    $this->getJson(route('dashboard.stats'))->assertForbidden();
});
