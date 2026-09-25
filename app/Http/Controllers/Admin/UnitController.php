<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Units of measure — shared by every branch. */
class UnitController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'units.restore');
        $search = trim((string) $request->query('search'));

        $units = $this->applyTab(Unit::query(), $tab)
            ->with('baseUnit')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('short_name', 'like', "%{$search}%")))
            ->orderByRaw('COALESCE(base_unit_id, id)')
            ->orderByRaw('base_unit_id IS NOT NULL')
            ->orderBy('factor')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('units/Index', [
            'units' => UnitResource::collection($units),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(Unit::class),
            'bases' => Unit::query()->whereNull('base_unit_id')->orderBy('name')->get()
                ->map(fn (Unit $u) => ['value' => $u->uuid, 'label' => "{$u->name} ({$u->short_name})", 'short_name' => $u->short_name]),
        ]);
    }

    public function store(UnitRequest $request): RedirectResponse
    {
        $unit = Unit::create($request->unitData());

        return back()->with('success', "Unit “{$unit->short_name}” added.");
    }

    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update($request->unitData());

        return back()->with('success', "Unit “{$unit->short_name}” saved.");
    }

    public function destroy(Request $request, Unit $unit): RedirectResponse
    {
        $unit->trash($this->trashReason($request));

        return back()->with('success', "Unit “{$unit->short_name}” moved to trash.");
    }

    public function restore(Unit $unit): RedirectResponse
    {
        $unit->restoreFromTrash();

        return back()->with('success', "Unit “{$unit->short_name}” restored.");
    }
}
