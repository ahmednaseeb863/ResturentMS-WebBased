<?php

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Reservation;
use App\Models\Shift;
use App\Support\CurrentBranch;
use App\Support\ShiftSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Reservations (PLAN §4.17) and expenses (PLAN §4.18) — Phase 14.
 */

const RSV_ROUTES = ['reservations.index', 'reservations.store', 'reservations.update', 'reservations.status'];
const EXP_ROUTES = [
    'expenses.index', 'expenses.store', 'expenses.void',
    'expense-categories.store', 'expense-categories.update', 'expense-categories.destroy', 'expense-categories.restore',
];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 14:00', 'Asia/Karachi'));
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];
    $this->table = DiningTable::factory()->forBranch($this->home)->create(['name' => 'T4', 'capacity' => 4]);
});

function rsvLogin(array $routes): Admin
{
    $admin = loginAdminWithRoutes($routes, test()->home);
    test()->withSession(test()->atHome);

    return $admin;
}

function rsvBook(array $data = []): Reservation
{
    test()->post(route('reservations.store'), [
        'guest_name' => 'Ali Raza',
        'guest_phone' => '0300-1112223',
        'party_size' => 4,
        'date' => '2026-09-27',
        'time' => '20:00',
        'table' => test()->table->uuid,
        'confirmed' => false,
        ...$data,
    ])->assertSessionHasNoErrors();

    return Reservation::query()->latest('id')->firstOrFail();
}

// ── Reservations ─────────────────────────────────────────────────────────

it('books a table, links the customer by phone and never double-books it', function () {
    rsvLogin(RSV_ROUTES);
    $customer = Customer::factory()->create(['name' => 'Ali Raza', 'phone' => '03001112223']);

    $reservation = rsvBook();
    expect($reservation)
        ->status->toBe(ReservationStatus::Pending)
        ->user_id->toBe($customer->id)
        ->duration_minutes->toBe(90)
        ->number->toBe(1);
    expect($reservation->code())->toBe('RSV-0001');
    expect($reservation->reserved_at->format('Y-m-d H:i'))->toBe('2026-09-27 20:00');

    // same table 21:00 overlaps 20:00–21:30; 21:30 is free
    $this->post(route('reservations.store'), ['guest_name' => 'Sara', 'party_size' => 2, 'date' => '2026-09-27', 'time' => '21:00', 'table' => $this->table->uuid])
        ->assertSessionHasErrors(['table' => 'T4 is booked 20:00–21:30 (RSV-0001, Ali Raza).']);
    $this->post(route('reservations.store'), ['guest_name' => 'Sara', 'party_size' => 2, 'date' => '2026-09-27', 'time' => '21:30', 'table' => $this->table->uuid, 'confirmed' => true])
        ->assertSessionHasNoErrors();
    expect(Reservation::query()->latest('id')->first()->status)->toBe(ReservationStatus::Confirmed);

    // the table seats 4; the past can't be booked
    $this->post(route('reservations.store'), ['guest_name' => 'Big party', 'party_size' => 9, 'date' => '2026-09-28', 'time' => '20:00', 'table' => $this->table->uuid])
        ->assertSessionHasErrors(['table' => 'T4 seats 4.']);
    $this->post(route('reservations.store'), ['guest_name' => 'Late', 'party_size' => 2, 'date' => '2026-09-25', 'time' => '20:00'])
        ->assertSessionHasErrors('date');

    // calendar: counts per day and the chosen day's bookings, no numeric ids
    $response = $this->get(route('reservations.index', ['date' => '2026-09-27']))->assertInertia(fn (Assert $page) => $page
        ->component('reservations/Index')
        ->where('month.days.2026-09-27.count', 2)
        ->where('month.days.2026-09-27.guests', 6)
        ->has('day', 2)
        ->where('day.0.time', '20:00')
        ->where('day.0.ends', '21:30'));
    expectNoNumericIds($response->inertiaProps());

    $this->get(route('reservations.index', ['view' => 'list', 'search' => 'ali']))
        ->assertInertia(fn (Assert $page) => $page->has('list.data', 1)->where('list.data.0.code', 'RSV-0001'));

    // other branch: not found
    loginAdminWithRoutes(RSV_ROUTES, $this->other);
    $this->withSession([CurrentBranch::SESSION_KEY => $this->other->id])
        ->put(route('reservations.status', $reservation), ['action' => 'confirm'])->assertNotFound();
});

it('confirms, seats, cancels and marks no-shows; closed ones are frozen and never deleted', function () {
    rsvLogin(RSV_ROUTES);
    $reservation = rsvBook(['date' => '2026-09-26', 'time' => '15:00']);
    $this->table->update(['status' => TableStatus::Reserved]);

    $this->put(route('reservations.status', $reservation), ['action' => 'no_show'])->assertSessionHasErrors('reservation');
    $this->put(route('reservations.status', $reservation), ['action' => 'confirm'])->assertSessionHasNoErrors();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed);

    $this->put(route('reservations.status', $reservation), ['action' => 'seat'])->assertSessionHasNoErrors();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Seated);
    expect($this->table->fresh()->status)->toBe(TableStatus::Available);

    // seated: can't be edited or cancelled any more
    $this->put(route('reservations.update', $reservation), ['guest_name' => 'X', 'party_size' => 2, 'date' => '2026-09-26', 'time' => '15:00'])
        ->assertSessionHasErrors('reservation');
    $this->put(route('reservations.status', $reservation), ['action' => 'cancel', 'reason' => 'x'])->assertSessionHasErrors('reservation');

    $other = rsvBook(['date' => '2026-09-26', 'time' => '14:20', 'table' => null]);
    $this->put(route('reservations.status', $other), ['action' => 'cancel'])->assertSessionHasErrors('reason');
    $this->travelTo(CarbonImmutable::parse('2026-09-26 14:45', 'Asia/Karachi'));
    $this->put(route('reservations.status', $other), ['action' => 'no_show'])->assertSessionHasNoErrors();
    expect($other->fresh())->status->toBe(ReservationStatus::NoShow)->closed_by->not->toBeNull();

    expect(fn () => $other->delete())->toThrow(PermanentDeleteNotAllowed::class);
});

// ── Expenses ─────────────────────────────────────────────────────────────

it('records an expense in cash from my shift drawer, never more than it holds', function () {
    Storage::fake('public');
    $admin = rsvLogin(EXP_ROUTES);
    $rent = ExpenseCategory::factory()->create(['name' => 'Electricity']);
    $counter = CashCounter::factory()->forBranch($this->home)->create();
    $shift = Shift::factory()->create(['branch_id' => $this->home->id, 'cash_counter_id' => $counter->id, 'opened_by' => $admin->id, 'opening_cash' => 5000]);

    $this->post(route('expenses.store'), ['category' => $rent->uuid, 'amount' => 6000, 'method' => 'cash', 'description' => 'Bill'])
        ->assertSessionHasErrors('amount');

    $this->post(route('expenses.store'), [
        'category' => $rent->uuid, 'amount' => 1200, 'method' => 'cash', 'description' => 'Electricity bill',
        'reference' => 'LESCO-9', 'attachment' => UploadedFile::fake()->image('bill.jpg'),
    ])->assertSessionHasNoErrors();

    $expense = Expense::query()->sole();
    expect($expense)
        ->paid_from->toBe(PaymentMethod::Cash)
        ->shift_id->toBe($shift->id)
        ->business_date->toDateString()->toBe($shift->business_date->toDateString());
    expect($expense->code())->toBe('EXP-0001');
    Storage::disk('public')->assertExists($expense->attachment);
    expect(CashMovement::query()->sole())->type->toBe(CashMovementType::Expense)->amount->toBe('1200.00')->reference_type->toBe('expense');
    expect(ShiftSummary::of($shift)->expectedCash())->toBe(3800.0);

    $response = $this->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page
        ->component('expenses/Index')
        ->has('expenses.data', 1)
        ->where('expenses.data.0.shift.code', $shift->code())
        ->where('stats.this_month', 1200)
        ->where('stats.cash_this_month', 1200)
        ->where('stats.top_category.name', 'Electricity')
        ->where('myShift.code', $shift->code()));
    expectNoNumericIds($response->inertiaProps());

    expect(fn () => $expense->delete())->toThrow(PermanentDeleteNotAllowed::class);
});

it('records a bank expense on a chosen day and voids expenses (cash goes back into the drawer)', function () {
    $admin = rsvLogin(EXP_ROUTES);
    $salaries = ExpenseCategory::factory()->create(['name' => 'Salaries']);
    $bank = BankAccount::factory()->forBranches($this->home)->create(['bank_name' => 'Meezan']);

    // no shift: cash is refused, bank needs an account
    $this->post(route('expenses.store'), ['category' => $salaries->uuid, 'amount' => 100, 'method' => 'cash', 'description' => 'x'])
        ->assertSessionHasErrors('method');
    $this->post(route('expenses.store'), ['category' => $salaries->uuid, 'amount' => 100, 'method' => 'bank_transfer', 'description' => 'x'])
        ->assertSessionHasErrors('bank');

    $this->post(route('expenses.store'), [
        'category' => $salaries->uuid, 'amount' => 50000, 'method' => 'bank_transfer', 'bank' => $bank->uuid,
        'business_date' => '2026-09-20', 'description' => 'Staff salaries',
    ])->assertSessionHasNoErrors();
    $bankExpense = Expense::query()->sole();
    expect($bankExpense)->business_date->toDateString()->toBe('2026-09-20')->bank_account_id->toBe($bank->id)->shift_id->toBeNull();

    // void a bank expense: no cash moves
    $this->put(route('expenses.void', $bankExpense), [])->assertSessionHasErrors('reason');
    $this->put(route('expenses.void', $bankExpense), ['reason' => 'Entered twice'])->assertSessionHasNoErrors();
    expect($bankExpense->fresh())->voided_at->not->toBeNull()->void_movement_id->toBeNull();
    $this->put(route('expenses.void', $bankExpense), ['reason' => 'again'])->assertSessionHasErrors('reason');

    // void a cash expense: back into the shift as cash in
    $shift = Shift::factory()->create(['branch_id' => $this->home->id, 'opened_by' => $admin->id, 'opening_cash' => 2000]);
    $this->post(route('expenses.store'), ['category' => $salaries->uuid, 'amount' => 500, 'method' => 'cash', 'description' => 'Tea'])->assertSessionHasNoErrors();
    $cash = Expense::query()->latest('id')->first();
    expect(ShiftSummary::of($shift)->expectedCash())->toBe(1500.0);
    $this->put(route('expenses.void', $cash), ['reason' => 'Wrong'])->assertSessionHasNoErrors();
    expect(ShiftSummary::of($shift)->expectedCash())->toBe(2000.0);
    expect(CashMovement::query()->latest('id')->first()->type)->toBe(CashMovementType::CashIn);

    // voided ones are not counted
    $this->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page->has('expenses.data', 0)->where('stats.this_month', 0));
    $this->get(route('expenses.index', ['status' => 'voided']))->assertInertia(fn (Assert $page) => $page->has('expenses.data', 2));
});

it('manages shared expense categories with trash and restore', function () {
    rsvLogin(EXP_ROUTES);

    $this->post(route('expense-categories.store'), ['name' => 'Rent'])->assertSessionHasNoErrors();
    $this->post(route('expense-categories.store'), ['name' => 'Rent'])->assertSessionHasErrors('name');
    $rent = ExpenseCategory::query()->where('name', 'Rent')->sole();

    $this->put(route('expense-categories.update', $rent), ['name' => 'Shop rent', 'is_active' => false])->assertSessionHasNoErrors();
    expect($rent->fresh())->name->toBe('Shop rent')->is_active->toBeFalse();

    // an inactive category can't take new expenses
    $this->post(route('expenses.store'), ['category' => $rent->uuid, 'amount' => 10, 'method' => 'bank_transfer', 'description' => 'x'])
        ->assertSessionHasErrors('category');

    $this->delete(route('expense-categories.destroy', $rent), ['reason' => 'Not used'])->assertSessionHasNoErrors();
    expect($rent->fresh()->isTrashed())->toBeTrue();
    $this->post(route('expense-categories.restore', $rent))->assertSessionHasNoErrors();
    expect($rent->fresh()->isTrashed())->toBeFalse();
    expect(fn () => $rent->delete())->toThrow(PermanentDeleteNotAllowed::class);
});

it('needs the route permissions', function () {
    rsvLogin(['reservations.index', 'expenses.index']);

    $this->post(route('reservations.store'), ['guest_name' => 'A', 'party_size' => 2, 'date' => '2026-09-27', 'time' => '20:00'])->assertForbidden();
    $this->post(route('expenses.store'), [])->assertForbidden();
    $this->post(route('expense-categories.store'), ['name' => 'X'])->assertForbidden();
    $this->get(route('reservations.index'))->assertOk();
});
