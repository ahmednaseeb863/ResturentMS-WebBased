<?php

use App\Enums\CashMovementType;
use App\Enums\ShiftStatus;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\CashMovement;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftCashCount;
use App\Models\ShiftType;
use App\Support\CurrentBranch;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Shifts, cash counters & cash (PLAN §4.15, §6).
 */

const CASHIER_ROUTES = ['shifts.index', 'shifts.show', 'shifts.report', 'shifts.open', 'shifts.cash', 'shifts.close', 'shifts.staff.store', 'shifts.staff.checkout', 'shifts.staff.destroy'];

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];
    $this->counter = CashCounter::factory()->forBranch($this->home)->create(['name' => 'Counter 1']);
});

function shiftSetting(Branch $branch, string $group, string $key, mixed $value): void
{
    Setting::create(['branch_id' => $branch->id, 'group' => $group, 'key' => $key, 'value' => $value]);
    app(SettingsResolver::class)->forget($branch->id);
}

function openShift(CashCounter $counter, Admin $cashier, array $attributes = []): Shift
{
    return Shift::factory()->create(['branch_id' => $counter->branch_id, 'cash_counter_id' => $counter->id, 'opened_by' => $cashier->id, ...$attributes]);
}

function counted(array $notes): array
{
    return collect($notes)->map(fn ($qty, $denomination) => ['denomination' => $denomination, 'quantity' => $qty])->values()->all();
}

it('opens a shift with a count, staff and the business date fixed at opening', function () {
    $cashier = loginAdminWithRoutes(CASHIER_ROUTES, $this->home);
    $night = ShiftType::factory()->forBranch($this->home)->create(['name' => 'Night', 'start_time' => '19:00', 'end_time' => '04:00']);
    $waiter = Employee::factory()->forBranch($this->home)->create(['name' => 'Bilal']);

    // 01:30 at night still belongs to the previous business day (cutoff 05:00)
    $this->travelTo(CarbonImmutable::parse('2026-09-26 01:30', 'Asia/Karachi'));

    $this->withSession($this->atHome)->get(route('shifts.index'))->assertInertia(fn (Assert $page) => $page
        ->component('shifts/Index')
        ->where('counters.0.label', 'Counter 1')
        ->where('counters.0.busy', false)
        ->where('suggestedType', $night->uuid)
        ->where('businessDate', '2026-09-25'));

    $this->post(route('shifts.open'), [
        'counter' => $this->counter->uuid,
        'shift_type' => $night->uuid,
        'opening_cash' => 1, // ignored: the count wins
        'count' => counted([1000 => 4, 500 => 2]),
        'staff' => [$waiter->uuid],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $shift = Shift::query()->forBranch($this->home)->sole();
    expect($shift)
        ->status->toBe(ShiftStatus::Open)
        ->opened_by->toBe($cashier->id)
        ->number->toBe(1)
        ->and($shift->business_date->toDateString())->toBe('2026-09-25')
        ->and((float) $shift->opening_cash)->toBe(5000.0)
        ->and($shift->code())->toBe('SHF-001')
        ->and($shift->counts()->count())->toBe(2)
        ->and($shift->staff()->sole()->employee->name)->toBe('Bilal')
        ->and($shift->scheduledEnd()->toDateTimeString())->toBe(CarbonImmutable::parse('2026-09-26 04:00', 'Asia/Karachi')->toDateTimeString())
        ->and($shift->isOverdue())->toBeFalse()
        ->and(ActivityLog::where('event', 'shift_opened')->sole()->properties)->toMatchArray(['counter' => 'Counter 1', 'opening_cash' => 5000]);

    // shared with every page: the status bar shows it, the POS will use it
    $response = $this->get(route('shifts.show', $shift))->assertInertia(fn (Assert $page) => $page
        ->component('shifts/Show')
        ->where('shift.code', 'SHF-001')
        ->where('shift.expected_cash', '5000')
        ->where('context.shift.code', 'SHF-001')
        ->where('context.shift.counter', 'Counter 1')
        ->where('context.business_date', '2026-09-25')
        ->has('staff', 1));
    expectNoNumericIds($response->inertiaProps());

    $this->travelTo(CarbonImmutable::parse('2026-09-26 04:30', 'Asia/Karachi'));
    expect($shift->fresh()->isOverdue())->toBeTrue();
});

it('allows one open shift per counter and per cashier', function () {
    $ali = Admin::factory()->create(['name' => 'Ali']);
    $shift = openShift($this->counter, $ali);
    $counter2 = CashCounter::factory()->forBranch($this->home)->create(['name' => 'Counter 2']);
    loginAdminWithRoutes(CASHIER_ROUTES, $this->home);

    $this->withSession($this->atHome)->post(route('shifts.open'), ['counter' => $this->counter->uuid, 'opening_cash' => 0])
        ->assertSessionHasErrors(['counter' => '“Counter 1” already has shift SHF-001 open (Ali).']);

    $this->post(route('shifts.open'), ['counter' => $counter2->uuid, 'opening_cash' => 0])->assertSessionHasNoErrors();
    $this->post(route('shifts.open'), ['counter' => $counter2->uuid, 'opening_cash' => 0])->assertSessionHasErrors('counter');

    $counter3 = CashCounter::factory()->forBranch($this->home)->create();
    $this->post(route('shifts.open'), ['counter' => $counter3->uuid, 'opening_cash' => 0])
        ->assertSessionHasErrors(['counter' => 'You already have shift SHF-002 open on “Counter 2” — close it first.']);

    // MySQL refuses it too
    expect(fn () => openShift($this->counter, Admin::factory()->create()))->toThrow(QueryException::class)
        ->and(fn () => openShift($counter3, $ali))->toThrow(QueryException::class)
        ->and(fn () => $shift->delete())->toThrow(PermanentDeleteNotAllowed::class);

    // a counter of another branch or an inactive one can't be picked
    $this->post(route('shifts.open'), ['counter' => CashCounter::factory()->forBranch($this->other)->create()->uuid, 'opening_cash' => 0])
        ->assertSessionHasErrors(['counter' => 'Pick an active cash counter of this branch.']);
});

it('records cash in, cash out and safe drops into the expected cash', function () {
    $cashier = loginAdminWithRoutes(CASHIER_ROUTES, $this->home);
    $shift = openShift($this->counter, $cashier, ['opening_cash' => 5000]);

    $this->withSession($this->atHome)->post(route('shifts.cash', $shift), ['type' => 'cash_in', 'amount' => 2000, 'reason' => 'Change from bank'])
        ->assertSessionHas('success', 'Cash in of Rs 2,000 recorded.');
    $this->post(route('shifts.cash', $shift), ['type' => 'cash_out', 'amount' => 300])->assertSessionHasErrors(['reason' => 'Say why the cash went in or out.']);
    $this->post(route('shifts.cash', $shift), ['type' => 'cash_out', 'amount' => 300, 'reason' => 'Ice'])->assertSessionHasNoErrors();
    $this->post(route('shifts.cash', $shift), ['type' => 'safe_drop', 'amount' => 4000])->assertSessionHasNoErrors();
    $this->post(route('shifts.cash', $shift), ['type' => 'safe_drop', 'amount' => 5000])->assertSessionHasErrors(['amount' => 'Only Rs 2,700 is in the drawer.']);
    $this->post(route('shifts.cash', $shift), ['type' => 'expense', 'amount' => 100])->assertSessionHasErrors('type');

    $this->get(route('shifts.show', $shift))->assertInertia(fn (Assert $page) => $page
        ->where('shift.expected_cash', '2700')
        ->has('movements', 3)
        ->where('movements.0.type.label', 'Safe drop')
        ->where('lines.0', ['key' => 'opening', 'label' => 'Opening cash', 'amount' => 5000, 'count' => 0])
        ->where('lines.1', ['key' => 'cash_sales', 'label' => 'Cash sales', 'amount' => 0, 'count' => 0])
        ->where('lines.2.amount', 2000)
        ->where('lines.3.amount', -300)
        ->where('lines.4.amount', -4000));

    $movement = CashMovement::query()->where('type', CashMovementType::CashOut)->sole();
    expect($movement->business_date->toDateString())->toBe($shift->business_date->toDateString())
        ->and(fn () => $movement->update(['amount' => 1]))->toThrow(PermanentDeleteNotAllowed::class)
        ->and(ActivityLog::where('event', 'cash_movement')->count())->toBe(3);

    // someone else's shift is off limits for a plain cashier
    $other = openShift(CashCounter::factory()->forBranch($this->home)->create(), Admin::factory()->create());
    $this->post(route('shifts.cash', $other), ['type' => 'cash_in', 'amount' => 10, 'reason' => 'x'])->assertForbidden();
});

it('closes with a count, keeps a float and asks a manager PIN above the allowed difference', function () {
    $cashier = loginAdminWithRoutes(CASHIER_ROUTES, $this->home);
    $manager = Admin::factory()->forBranches($this->home)->withRole(Role::factory()->withRoutes('shifts.reopen')->create())->create(['name' => 'Sana', 'pin' => '4321']);
    Admin::factory()->forBranches($this->other)->withRole(Role::factory()->withRoutes('shifts.reopen')->create())->create(['pin' => '5555']);
    $cashier->update(['pin' => '1111']);
    $shift = openShift($this->counter, $cashier, ['opening_cash' => 5000]);
    $shift->staff()->create(['employee_id' => Employee::factory()->forBranch($this->home)->create()->id, 'checked_in_at' => now()]);

    // 4,000 counted against 5,000 expected: short by 1,000, limit is 500
    $close = ['count' => counted([1000 => 4]), 'float_left' => 3000];
    $this->withSession($this->atHome)->put(route('shifts.close', $shift), $close)
        ->assertSessionHasErrors(['pin' => 'The count is short by Rs 1,000 — more than the Rs 500 allowed. Recount, or ask a manager to enter their PIN.']);
    $this->put(route('shifts.close', $shift), [...$close, 'pin' => '1111'])->assertSessionHasErrors(['pin' => 'PIN not accepted — it must be the PIN of a manager of this branch.']);
    $this->put(route('shifts.close', $shift), [...$close, 'pin' => '5555'])->assertSessionHasErrors('pin'); // manager of another branch
    $this->put(route('shifts.close', $shift), [...$close, 'float_left' => 4500])->assertSessionHasErrors('float_left');
    expect($shift->fresh()->isOpen())->toBeTrue();

    $this->put(route('shifts.close', $shift), [...$close, 'pin' => '4321'])
        ->assertSessionHas('success', 'Shift SHF-001 closed — short by Rs 1,000.');

    $shift->refresh();
    expect($shift)
        ->status->toBe(ShiftStatus::Closed)
        ->approved_by->toBe($manager->id)
        ->closed_by->toBe($cashier->id)
        ->and((float) $shift->expected_cash)->toBe(5000.0)
        ->and((float) $shift->counted_cash)->toBe(4000.0)
        ->and((float) $shift->difference)->toBe(-1000.0)
        ->and((float) $shift->handed_over_amount)->toBe(1000.0)
        ->and($shift->staff()->sole()->checked_out_at)->not->toBeNull()
        ->and(ActivityLog::where('event', 'shift_closed')->sole()->properties)->toMatchArray(['difference' => -1000, 'approved_by' => 'Sana']);

    // the float is suggested as the next opening cash of the counter
    $this->get(route('shifts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('counters.0.float', 3000)
        ->where('stats.short', -1000)
        ->where('stats.closed', 1));
});

it('needs the notes counted when the branch requires it and hides the expected cash on blind close', function () {
    $cashier = loginAdminWithRoutes(CASHIER_ROUTES, $this->home);
    $shift = openShift($this->counter, $cashier, ['opening_cash' => 2000]);
    shiftSetting($this->home, 'shifts', 'blind_close', true);

    $this->withSession($this->atHome)->get(route('shifts.show', $shift))->assertInertia(fn (Assert $page) => $page
        ->where('shift.expected_cash', null)
        ->where('shift.cash_visible', false)
        ->where('lines', []));
    $this->post(route('shifts.cash', $shift), ['type' => 'safe_drop', 'amount' => 9000])
        ->assertSessionHasErrors(['amount' => 'That is more than the cash in the drawer.']);

    $this->put(route('shifts.close', $shift), ['counted_cash' => 2000, 'float_left' => 0])
        ->assertSessionHasErrors(['count' => 'Count the notes and coins in the drawer.']);

    shiftSetting($this->home, 'shifts', 'require_denominations', false);
    $this->put(route('shifts.close', $shift), ['counted_cash' => 2100, 'float_left' => 0])
        ->assertSessionHas('success', 'Shift SHF-001 closed — over by Rs 100.');

    // after closing the result is shown
    $this->get(route('shifts.show', $shift))->assertInertia(fn (Assert $page) => $page->where('shift.expected_cash', '2000.00'));
});

it('reopens only the latest shift of a counter, with a manager PIN', function () {
    $cashier = Admin::factory()->create(['name' => 'Ali']);
    $older = openShift($this->counter, $cashier, ['status' => ShiftStatus::Closed, 'closed_at' => now()]);
    $shift = openShift($this->counter, $cashier);
    $manager = loginAdminWithRoutes([...CASHIER_ROUTES, 'shifts.reopen'], $this->home);
    $manager->update(['pin' => '9999']);

    $this->withSession($this->atHome)->put(route('shifts.close', $shift), ['count' => counted([1000 => 5]), 'float_left' => 1000])->assertSessionHasNoErrors();
    expect(ShiftCashCount::query()->where('shift_id', $shift->id)->count())->toBe(1);

    $this->put(route('shifts.reopen', $older), ['reason' => 'Oops', 'pin' => '9999'])
        ->assertSessionHasErrors(['reason' => 'Shift SHF-002 has already run on “Counter 1” — only the latest shift of a counter can be reopened.']);
    $this->put(route('shifts.reopen', $shift), ['reason' => 'Missed a cash out'])->assertSessionHasErrors(['pin' => 'A manager must enter their PIN.']);
    $this->put(route('shifts.reopen', $shift), ['reason' => 'Missed a cash out', 'pin' => '9999'])->assertSessionHas('success');

    $shift->refresh();
    expect($shift)
        ->status->toBe(ShiftStatus::Open)
        ->counted_cash->toBeNull()
        ->reopened_by->toBe($manager->id)
        ->reopen_count->toBe(1)
        ->and(ShiftCashCount::query()->where('shift_id', $shift->id)->count())->toBe(0)
        ->and(ShiftCashCount::onlyTrashed()->where('shift_id', $shift->id)->sole()->delete_reason)->toBe('Shift reopened')
        ->and(ActivityLog::where('event', 'shift_reopened')->sole()->properties)->toMatchArray(['reason' => 'Missed a cash out', 'was_counted' => 5000]);

    // a manager handles the cashier's shift: closes it again
    $this->put(route('shifts.close', $shift), ['count' => counted([1000 => 5]), 'float_left' => 0])->assertSessionHasNoErrors();

    // the cashier already on another counter can't get this shift back
    openShift(CashCounter::factory()->forBranch($this->home)->create(['name' => 'Counter 2']), $cashier);
    $this->put(route('shifts.reopen', $shift), ['reason' => 'Again', 'pin' => '9999'])->assertSessionHasErrors('reason');
});

it('lets only shift managers reopen and prints X and Z reports', function () {
    $cashier = loginAdminWithRoutes(CASHIER_ROUTES, $this->home);
    $shift = openShift($this->counter, $cashier, ['opening_cash' => 1500]);
    CashMovement::create(['branch_id' => $this->home->id, 'shift_id' => $shift->id, 'business_date' => $shift->business_date, 'type' => CashMovementType::CashIn, 'amount' => 250, 'reason' => 'Float top-up']);

    $this->withSession($this->atHome)->get(route('shifts.report', $shift))->assertOk()
        ->assertSee('X-REPORT')->assertSee('Float top-up')->assertSee('Rs 1,750');

    $this->put(route('shifts.close', $shift), ['count' => counted([1000 => 1, 500 => 1, 100 => 2, 50 => 1]), 'float_left' => 0])->assertSessionHasNoErrors();
    $this->get(route('shifts.report', $shift))->assertOk()->assertSee('Z-REPORT')->assertSee('Balanced');
    expect(ActivityLog::where('event', 'shift_report')->count())->toBe(2);

    $this->put(route('shifts.reopen', $shift), ['reason' => 'x'])->assertForbidden();
});

it('lists shifts with stats and keeps other branches out', function () {
    loginSuperAdmin();
    $ali = Admin::factory()->create(['name' => 'Ali']);
    openShift($this->counter, $ali, ['status' => ShiftStatus::Closed, 'difference' => 200, 'counted_cash' => 5200, 'expected_cash' => 5000, 'business_date' => '2026-09-01']);
    openShift($this->counter, $ali, ['status' => ShiftStatus::Closed, 'difference' => -50, 'counted_cash' => 4950, 'expected_cash' => 5000, 'business_date' => '2026-09-02']);
    $open = openShift($this->counter, $ali);
    $foreign = Shift::factory()->forBranch($this->other)->create();

    $response = $this->withSession($this->atHome)->get(route('shifts.index'))->assertInertia(fn (Assert $page) => $page
        ->component('shifts/Index')
        ->has('shifts.data', 3)
        ->has('openShifts', 1)
        ->where('openShifts.0.id', $open->uuid)
        ->where('stats', ['closed' => 2, 'open' => 1, 'over' => 200, 'short' => -50, 'off' => 2])
        ->where('counters.0.busy', true));
    expectNoNumericIds($response->inertiaProps());

    $this->get(route('shifts.index', ['from' => '2026-09-02', 'status' => 'closed']))->assertInertia(fn (Assert $page) => $page
        ->has('shifts.data', 1)
        ->where('stats.closed', 1));

    $this->get(route('shifts.show', $foreign))->assertNotFound();
    $this->post(route('shifts.cash', $foreign), ['type' => 'cash_in', 'amount' => 1, 'reason' => 'x'])->assertNotFound();
});

it('keeps a counter with an open shift from being trashed or switched off', function () {
    loginSuperAdmin();
    openShift($this->counter, Admin::factory()->create());

    expect(fn () => $this->counter->trash())->toThrow(TrashNotAllowed::class, 'Shift SHF-001 is open on it');

    $this->withSession($this->atHome)->put(route('counters.update', $this->counter), ['name' => 'Counter 1', 'is_active' => false])
        ->assertSessionHasErrors(['is_active' => 'Shift SHF-001 is open on this counter — close it first.']);
});

it('checks staff in and out of a shift', function () {
    $cashier = loginAdminWithRoutes(CASHIER_ROUTES, $this->home);
    $shift = openShift($this->counter, $cashier);
    $bilal = Employee::factory()->forBranch($this->home)->create(['name' => 'Bilal']);
    $sara = Employee::factory()->forBranch($this->home)->create(['name' => 'Sara']);
    $foreign = Employee::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)->post(route('shifts.staff.store', $shift), ['staff' => [$bilal->uuid, $sara->uuid]])
        ->assertSessionHas('success', 'Bilal and Sara checked in.');
    $this->post(route('shifts.staff.store', $shift), ['staff' => [$bilal->uuid]])->assertSessionHasErrors(['staff' => 'Bilal is already on duty.']);
    $this->post(route('shifts.staff.store', $shift), ['staff' => [$foreign->uuid]])->assertSessionHasErrors(['staff' => 'Pick active staff of this branch.']);

    [$first, $second] = $shift->staff()->get();
    $this->put(route('shifts.staff.checkout', [$shift, $first]))->assertSessionHas('success', 'Bilal checked out.');
    $this->delete(route('shifts.staff.destroy', [$shift, $second]))->assertSessionHas('success');

    expect($first->fresh()->checked_out_at)->not->toBeNull()
        ->and($second->fresh()->isTrashed())->toBeTrue()
        ->and(fn () => $second->fresh()->delete())->toThrow(PermanentDeleteNotAllowed::class);

    // a staff line of another shift is not reachable through this one
    $otherShift = openShift(CashCounter::factory()->forBranch($this->home)->create(), Admin::factory()->create());
    $line = $otherShift->staff()->create(['employee_id' => $bilal->id, 'checked_in_at' => now()]);
    $this->put(route('shifts.staff.checkout', [$shift, $line]))->assertNotFound();
});
