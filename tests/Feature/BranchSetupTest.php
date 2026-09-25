<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Printer;
use App\Models\ShiftType;
use App\Support\CurrentBranch;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Per-branch setup records: printers, cash counters, shift types (PLAN §4.5.2, §6).
 */

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];
});

// ── Printers ──────────────────────────────────────────────────────────────

it('adds a network printer in the current branch and lists only this branch', function () {
    loginSuperAdmin();
    Printer::factory()->forBranch($this->other)->create(['name' => 'Elsewhere']);

    $this->withSession($this->atHome)->post(route('printers.store'), [
        'name' => 'Grill KOT',
        'type' => 'kitchen',
        'connection' => 'network',
        'ip_address' => '192.168.1.60',
        'device_name' => 'ignored',
        'paper_width' => '80',
    ])->assertSessionHasNoErrors();

    $printer = Printer::query()->allBranches()->where('name', 'Grill KOT')->sole();
    expect($printer)->branch_id->toBe($this->home->id)
        ->device_name->toBeNull()
        ->port->toBe(9100)
        ->and($printer->address())->toBe('192.168.1.60:9100');

    $response = $this->withSession($this->atHome)->get(route('printers.index'))->assertInertia(fn (Assert $page) => $page
        ->component('printers/Index')
        ->has('printers.data', 1)
        ->where('printers.data.0.name', 'Grill KOT')
        ->where('printMethod', 'browser'));

    expectNoNumericIds($response->inertiaProps());
});

it('needs the PC printer name for USB printers and a unique name per branch', function () {
    loginSuperAdmin();
    Printer::factory()->forBranch($this->home)->create(['name' => 'Counter Receipt']);
    Printer::factory()->forBranch($this->other)->create(['name' => 'Same Name Elsewhere']);

    $this->withSession($this->atHome)->post(route('printers.store'), [
        'name' => 'Counter Receipt', 'type' => 'receipt', 'connection' => 'usb', 'paper_width' => 80,
    ])->assertSessionHasErrors(['name', 'device_name']);

    $this->post(route('printers.store'), [
        'name' => 'Same Name Elsewhere', 'type' => 'receipt', 'connection' => 'usb', 'device_name' => 'EPSON', 'paper_width' => 58,
    ])->assertSessionHasNoErrors();
});

it('prints a test slip and records it', function () {
    $this->withoutVite();
    loginSuperAdmin();
    $printer = Printer::factory()->forBranch($this->home)->create(['name' => 'Front Receipt']);

    $this->withSession($this->atHome)->get(route('printers.test', $printer))
        ->assertOk()
        ->assertSee('TEST PRINT')
        ->assertSee('Front Receipt');

    expect($printer->fresh()->last_tested_at)->not->toBeNull()
        ->and(ActivityLog::where('event', 'test_print')->count())->toBe(1);
});

it('cannot open another branch printer', function () {
    loginSuperAdmin();
    $printer = Printer::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)->get(route('printers.test', $printer))->assertNotFound();
});

// ── Cash counters ─────────────────────────────────────────────────────────

it('links a counter to a receipt printer of the same branch only', function () {
    loginSuperAdmin();
    $receipt = Printer::factory()->forBranch($this->home)->create();
    $kitchen = Printer::factory()->forBranch($this->home)->kitchen()->create();
    $foreign = Printer::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)->post(route('counters.store'), ['name' => 'Counter 2', 'receipt_printer' => $kitchen->uuid])
        ->assertSessionHasErrors('receipt_printer');
    $this->post(route('counters.store'), ['name' => 'Counter 2', 'receipt_printer' => $foreign->uuid])
        ->assertSessionHasErrors('receipt_printer');
    $this->post(route('counters.store'), ['name' => 'Counter 2', 'receipt_printer' => $receipt->uuid])
        ->assertSessionHasNoErrors();

    $counter = CashCounter::query()->forBranch($this->home)->sole();
    expect($counter->receipt_printer_id)->toBe($receipt->id);

    $response = $this->get(route('counters.index'))->assertInertia(fn (Assert $page) => $page
        ->component('counters/Index')
        ->where('counters.data.0.receipt_printer.name', $receipt->name)
        ->has('printers', 1));

    expectNoNumericIds($response->inertiaProps());
});

it('refuses to trash a printer a counter uses, or to turn it into a kitchen printer', function () {
    loginSuperAdmin();
    $printer = Printer::factory()->forBranch($this->home)->create();
    $counter = CashCounter::factory()->forBranch($this->home)->create(['name' => 'Counter 1', 'receipt_printer_id' => $printer->id]);

    expect(fn () => $printer->trash())->toThrow(TrashNotAllowed::class, 'Used by “Counter 1”');

    $this->withSession($this->atHome)->put(route('printers.update', $printer), [
        'name' => $printer->name, 'type' => 'kitchen', 'connection' => 'usb', 'device_name' => 'EPSON', 'paper_width' => 80,
    ])->assertSessionHasErrors('type');

    // once the counter is trashed, the printer can go — and the counter waits for it on restore
    $counter->trash();
    $printer->trash();
    expect(fn () => $counter->restoreFromTrash())->toThrow(TrashNotAllowed::class);
});

it('trashes and restores a counter', function () {
    loginSuperAdmin();
    $counter = CashCounter::factory()->forBranch($this->home)->create();

    $this->withSession($this->atHome)->delete(route('counters.destroy', $counter))->assertSessionHas('success');
    expect($counter->fresh()->isTrashed())->toBeTrue();

    $this->post(route('counters.restore', $counter))->assertSessionHas('success');
    expect($counter->fresh()->isTrashed())->toBeFalse()
        ->and(fn () => $counter->delete())->toThrow(PermanentDeleteNotAllowed::class);
});

it('cannot edit another branch counter', function () {
    loginSuperAdmin();
    $counter = CashCounter::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)->put(route('counters.update', $counter), ['name' => 'Hijack'])->assertNotFound();
});

// ── Shift types ───────────────────────────────────────────────────────────

it('adds an overnight shift type and shows its length', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('shift-types.store'), ['name' => 'Night', 'start_time' => '19:00', 'end_time' => '04:00'])
        ->assertSessionHasNoErrors();

    $shift = ShiftType::query()->forBranch($this->home)->sole();
    expect($shift->isOvernight())->toBeTrue()->and($shift->durationMinutes())->toBe(540);

    $response = $this->get(route('shift-types.index'))->assertInertia(fn (Assert $page) => $page
        ->component('shift-types/Index')
        ->where('shiftTypes.data.0.start_time', '19:00')
        ->where('shiftTypes.data.0.end_time', '04:00')
        ->where('shiftTypes.data.0.overnight', true)
        ->where('cutoff', '05:00'));

    expectNoNumericIds($response->inertiaProps());
});

it('rejects a shift that ends when it starts', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('shift-types.store'), ['name' => 'Odd', 'start_time' => '10:00', 'end_time' => '10:00'])
        ->assertSessionHasErrors('end_time');
});

it('needs the shift types permission', function () {
    loginAdminWithRoutes(['shift-types.index'], $this->home);

    $this->withSession($this->atHome)->get(route('shift-types.index'))->assertOk();
    $this->post(route('shift-types.store'), ['name' => 'X', 'start_time' => '10:00', 'end_time' => '12:00'])->assertForbidden();
});
