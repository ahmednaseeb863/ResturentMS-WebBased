<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiningTableRequest;
use App\Http\Requests\TableLayoutRequest;
use App\Http\Resources\AreaResource;
use App\Http\Resources\DiningTableResource;
use App\Models\Area;
use App\Models\DiningTable;
use App\Support\Activity;
use App\Support\FloorPlan;
use App\Support\MenuOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dining tables of the current branch: the list (add / edit / trash), the floor plan
 * with live status, arranging the plan, and setting a status by hand.
 */
class DiningTableController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'tables.restore');
        $search = trim((string) $request->query('search'));
        $area = (string) $request->query('area');

        $tables = $this->applyTab(DiningTable::query(), $tab)
            ->with('area')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($area !== '', fn ($q) => $q->whereHas('area', fn ($q) => $q->where('uuid', $area)))
            ->orderBy('area_id')
            ->orderByRaw('LENGTH(name), name') // T2 before T10
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('tables/Index', [
            'tables' => DiningTableResource::collection($tables),
            'filters' => ['tab' => $tab, 'search' => $search, 'area' => $area],
            'counts' => $this->tabCounts(DiningTable::class),
            'seats' => (int) DiningTable::query()->sum('capacity'),
            'areas' => MenuOptions::areas(),
            'shapes' => TableShape::options(),
        ]);
    }

    /** The floor plan: one tab per area, tables at their spots coloured by status. */
    public function floor(): Response
    {
        $areas = Area::query()->where('is_active', true)->ordered()->get();
        $tables = DiningTable::query()->whereIn('area_id', $areas->pluck('id'))->with('area')
            ->orderByRaw('LENGTH(name), name')->get();

        return Inertia::render('tables/Floor', [
            'areas' => AreaResource::collection($areas),
            'tables' => DiningTableResource::collection($tables),
            'statuses' => TableStatus::options(),
            'grid' => ['cols' => DiningTable::GRID_COLS, 'rows' => DiningTable::GRID_ROWS],
        ]);
    }

    public function store(DiningTableRequest $request): RedirectResponse
    {
        $table = new DiningTable($request->tableData());
        [$table->pos_x, $table->pos_y] = FloorPlan::freeSpot($table->area_id, $table->shape) ?? [null, null];
        $table->save();

        $where = $table->pos_x === null ? ' The floor plan is full — place it from the plan.' : '';

        return back()->with('success', "Table “{$table->name}” added.{$where}");
    }

    public function update(DiningTableRequest $request, DiningTable $table): RedirectResponse
    {
        $table->fill($request->tableData());

        // a new area or a bigger shape may need a new spot
        if ($table->isDirty(['area_id', 'shape']) || $table->pos_x === null) {
            $keep = ! $table->isDirty('area_id') && $table->pos_x !== null && $this->fits($table);
            if (! $keep) {
                [$table->pos_x, $table->pos_y] = FloorPlan::freeSpot($table->area_id, $table->shape, $table->id) ?? [null, null];
            }
        }
        $table->save();

        return back()->with('success', "Table “{$table->name}” saved.");
    }

    public function destroy(Request $request, DiningTable $table): RedirectResponse
    {
        $table->trash($this->trashReason($request));

        return back()->with('success', "Table “{$table->name}” moved to trash.");
    }

    public function restore(DiningTable $table): RedirectResponse
    {
        $table->restoreFromTrash();

        if ($table->pos_x !== null && ! $this->fits($table)) {
            [$x, $y] = FloorPlan::freeSpot($table->area_id, $table->shape, $table->id) ?? [null, null];
            $table->forceFill(['pos_x' => $x, 'pos_y' => $y])->saveQuietly();
        }

        return back()->with('success', "Table “{$table->name}” restored.");
    }

    /** Save the arranged floor plan of one area. */
    public function layout(TableLayoutRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            foreach ($request->positions() as $uuid => [$x, $y]) {
                $request->tables()[$uuid]->forceFill(['pos_x' => $x, 'pos_y' => $y])->save();
            }

            Activity::log('floor_arranged', $request->area(), ['tables' => $request->tables()->pluck('name')->values()->all()]);
        });

        return back()->with('success', "Floor plan of “{$request->area()->name}” saved.");
    }

    /** Available / reserved / cleaning by hand; occupied comes from orders. */
    public function status(Request $request, DiningTable $table): RedirectResponse
    {
        $manual = collect(TableStatus::cases())->filter->isManual()->map->value->all();
        $data = $request->validate(['status' => ['required', Rule::in($manual)]]);

        if ($table->status === TableStatus::Occupied) {
            return back()->withErrors(['status' => "“{$table->name}” has an open order — its status changes with the order."]);
        }

        $from = $table->status;
        $table->forceFill(['status' => $data['status']])->save();

        if ($from !== $table->status) {
            Activity::log('table_status', $table, ['from' => $from->label(), 'to' => $table->status->label()]);
        }

        return back()->with('success', "Table “{$table->name}” is now {$table->status->label()}.");
    }

    /** The table's current spot is inside the grid and free. */
    private function fits(DiningTable $table): bool
    {
        $rect = FloorPlan::rect($table, $table->pos_x, $table->pos_y);

        return $rect['x'] + $rect['w'] <= DiningTable::GRID_COLS
            && $rect['y'] + $rect['h'] <= DiningTable::GRID_ROWS
            && ! FloorPlan::hits(FloorPlan::placed($table->area_id, $table->id), $rect['x'], $rect['y'], $rect['w'], $rect['h']);
    }
}
