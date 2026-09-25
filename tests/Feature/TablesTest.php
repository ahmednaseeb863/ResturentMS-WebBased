<?php

use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Support\CurrentBranch;
use App\Support\FloorPlan;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Dining areas, tables and the floor plan (PLAN §4.9).
 */

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];
    $this->hall = Area::factory()->forBranch($this->home)->create(['name' => 'Hall']);
});

function tableIn(Area $area, array $attributes = []): DiningTable
{
    return DiningTable::factory()->create(['branch_id' => $area->branch_id, 'area_id' => $area->id, ...$attributes]);
}

it('adds tables on the first free spot of the plan', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('tables.store'), ['name' => 'T1', 'area' => $this->hall->uuid, 'capacity' => 4, 'shape' => 'square'])
        ->assertSessionHasNoErrors();
    $this->post(route('tables.store'), ['name' => 'T2', 'area' => $this->hall->uuid, 'capacity' => 6, 'shape' => 'rectangle'])
        ->assertSessionHasNoErrors();

    $t1 = DiningTable::query()->forBranch($this->home)->where('name', 'T1')->sole();
    $t2 = DiningTable::query()->forBranch($this->home)->where('name', 'T2')->sole();
    expect([$t1->pos_x, $t1->pos_y])->toBe([0, 0])
        ->and([$t2->pos_x, $t2->pos_y])->toBe([2, 0])   // right next to T1 (2 cells wide)
        ->and($t1->status)->toBe(TableStatus::Available);

    $this->post(route('tables.store'), ['name' => 't1', 'area' => $this->hall->uuid, 'capacity' => 2, 'shape' => 'round'])
        ->assertSessionHasErrors('name');
    $this->post(route('tables.store'), ['name' => 'T3', 'area' => Area::factory()->forBranch($this->other)->create()->uuid, 'capacity' => 2, 'shape' => 'round'])
        ->assertSessionHasErrors(['area' => 'Pick an area of this branch.']);

    $response = $this->get(route('tables.index'))->assertInertia(fn (Assert $page) => $page
        ->component('tables/Index')
        ->has('tables.data', 2)
        ->where('seats', 10)
        ->where('tables.data.0.area.name', 'Hall'));
    expectNoNumericIds($response->inertiaProps());
});

it('moves a table to a free spot when its area or shape changes', function () {
    loginSuperAdmin();
    $t1 = tableIn($this->hall, ['name' => 'T1', 'pos_x' => 0, 'pos_y' => 0]);
    tableIn($this->hall, ['name' => 'T2', 'pos_x' => 2, 'pos_y' => 0]);

    // square → long (5 wide) would run into T2
    $this->withSession($this->atHome)->put(route('tables.update', $t1), ['name' => 'T1', 'area' => $this->hall->uuid, 'capacity' => 10, 'shape' => 'long'])
        ->assertSessionHasNoErrors();
    expect([$t1->fresh()->pos_x, $t1->fresh()->pos_y])->toBe([4, 0]);

    $rooftop = Area::factory()->forBranch($this->home)->create(['name' => 'Rooftop']);
    $this->put(route('tables.update', $t1), ['name' => 'T1', 'area' => $rooftop->uuid, 'capacity' => 10, 'shape' => 'long']);
    expect($t1->fresh())->area_id->toBe($rooftop->id)->pos_x->toBe(0)->pos_y->toBe(0);
});

it('saves an arranged floor plan and refuses overlaps', function () {
    loginSuperAdmin();
    $t1 = tableIn($this->hall, ['name' => 'T1', 'pos_x' => 0, 'pos_y' => 0]);
    $t2 = tableIn($this->hall, ['name' => 'T2', 'pos_x' => 4, 'pos_y' => 0]);
    $t3 = tableIn($this->hall, ['name' => 'T3']); // not placed yet

    $this->withSession($this->atHome)->put(route('tables.layout'), ['area' => $this->hall->uuid, 'tables' => [
        ['id' => $t1->uuid, 'x' => 3, 'y' => 1],
    ]])->assertSessionHasErrors(['tables' => '“T1” overlaps “T2”.']);

    $this->put(route('tables.layout'), ['area' => $this->hall->uuid, 'tables' => [
        ['id' => $t1->uuid, 'x' => DiningTable::GRID_COLS - 1, 'y' => 0],
    ]])->assertSessionHasErrors(['tables' => '“T1” does not fit there.']);

    // swap T1 and T2, place T3
    $this->put(route('tables.layout'), ['area' => $this->hall->uuid, 'tables' => [
        ['id' => $t1->uuid, 'x' => 4, 'y' => 0],
        ['id' => $t2->uuid, 'x' => 0, 'y' => 0],
        ['id' => $t3->uuid, 'x' => 10, 'y' => 6],
    ]])->assertSessionHasNoErrors();

    expect([$t1->fresh()->pos_x, $t2->fresh()->pos_x, $t3->fresh()->pos_y])->toBe([4, 0, 6])
        ->and(ActivityLog::where('event', 'floor_arranged')->sole()->properties['tables'])->toBe(['T1', 'T2', 'T3']);

    // a table of another area is refused
    $other = tableIn(Area::factory()->forBranch($this->home)->create());
    $this->put(route('tables.layout'), ['area' => $this->hall->uuid, 'tables' => [['id' => $other->uuid, 'x' => 0, 'y' => 8]]])
        ->assertSessionHasErrors('tables');
});

it('shows the floor plan and sets a status by hand, but never over an open order', function () {
    loginSuperAdmin();
    $t1 = tableIn($this->hall, ['name' => 'T1', 'pos_x' => 0, 'pos_y' => 0]);
    $busy = tableIn($this->hall, ['name' => 'T2', 'status' => TableStatus::Occupied]);
    Area::factory()->forBranch($this->home)->create(['name' => 'Closed', 'is_active' => false]);
    tableIn(Area::factory()->forBranch($this->other)->create(), ['name' => 'Elsewhere']);

    $response = $this->withSession($this->atHome)->get(route('tables.floor'))->assertInertia(fn (Assert $page) => $page
        ->component('tables/Floor')
        ->has('areas', 1)
        ->has('tables', 2)
        ->where('grid.cols', DiningTable::GRID_COLS)
        ->where('tables.0.w', 2));
    expectNoNumericIds($response->inertiaProps());

    $this->put(route('tables.status', $t1), ['status' => 'cleaning'])->assertSessionHas('success');
    expect($t1->fresh()->status)->toBe(TableStatus::Cleaning)
        ->and(ActivityLog::where('event', 'table_status')->sole()->properties)->toEqual(['from' => 'Available', 'to' => 'Cleaning']);

    $this->put(route('tables.status', $t1), ['status' => 'occupied'])->assertSessionHasErrors('status');
    $this->put(route('tables.status', $busy), ['status' => 'available'])->assertSessionHasErrors('status');
    expect($busy->fresh()->status)->toBe(TableStatus::Occupied)
        ->and(fn () => $busy->trash())->toThrow(TrashNotAllowed::class, 'open order');
});

it('trashes and restores tables and areas', function () {
    loginSuperAdmin();
    $t1 = tableIn($this->hall, ['name' => 'T1', 'pos_x' => 0, 'pos_y' => 0]);

    expect(fn () => $this->hall->trash())->toThrow(TrashNotAllowed::class, 'It still has 1 table');

    $this->withSession($this->atHome)->delete(route('tables.destroy', $t1))->assertSessionHas('success');
    $t2 = tableIn($this->hall, ['name' => 'T2', 'pos_x' => 0, 'pos_y' => 0]); // takes the old spot

    $this->post(route('tables.restore', $t1))->assertSessionHas('success');
    expect($t1->fresh())->isTrashed()->toBeFalse()->pos_x->toBe(2); // moved next to T2

    $t1->trash();
    $t2->trash();
    $this->delete(route('areas.destroy', $this->hall))->assertSessionHas('success');
    expect(fn () => $t1->fresh()->restoreFromTrash())->toThrow(TrashNotAllowed::class, 'Restore the Area “Hall” first')
        ->and(fn () => $this->hall->delete())->toThrow(PermanentDeleteNotAllowed::class);

    $this->post(route('areas.restore', $this->hall))->assertSessionHas('success');
    $response = $this->get(route('areas.index'))->assertInertia(fn (Assert $page) => $page
        ->component('areas/Index')
        ->where('areas.data.0.tables_count', 0));
    expectNoNumericIds($response->inertiaProps());
});

it('keeps other branches tables out of reach', function () {
    loginSuperAdmin();
    $foreignArea = Area::factory()->forBranch($this->other)->create();
    $foreign = tableIn($foreignArea, ['name' => 'X1']);

    $this->withSession($this->atHome)->put(route('tables.update', $foreign), ['name' => 'X1', 'area' => $this->hall->uuid, 'capacity' => 2, 'shape' => 'square'])
        ->assertNotFound();
    $this->put(route('tables.status', $foreign), ['status' => 'cleaning'])->assertNotFound();
    $this->put(route('tables.layout'), ['area' => $foreignArea->uuid, 'tables' => [['id' => $foreign->uuid, 'x' => 0, 'y' => 0]]])
        ->assertSessionHasErrors('area');
});

it('lets a waiter see the floor and set a status only', function () {
    $t1 = tableIn($this->hall, ['name' => 'T1']);
    loginAdminWithRoutes(['tables.floor', 'tables.status'], $this->home);

    $this->withSession($this->atHome)->get(route('tables.floor'))->assertOk();
    $this->put(route('tables.status', $t1), ['status' => 'reserved'])->assertSessionHas('success');
    $this->put(route('tables.layout'), ['area' => $this->hall->uuid, 'tables' => [['id' => $t1->uuid, 'x' => 1, 'y' => 1]]])->assertForbidden();
    $this->get(route('tables.index'))->assertForbidden();
});

it('finds free spots on the grid', function () {
    tableIn($this->hall, ['pos_x' => 0, 'pos_y' => 0, 'shape' => TableShape::Long]);

    expect(FloorPlan::freeSpot($this->hall->id, TableShape::Square))->toBe([5, 0])
        ->and(DiningTable::clampPosition(TableShape::Long, 30, 30))->toBe([DiningTable::GRID_COLS - 5, DiningTable::GRID_ROWS - 2]);
});
