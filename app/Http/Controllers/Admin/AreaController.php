<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\AreaRequest;
use App\Http\Resources\AreaResource;
use App\Models\Area;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Dining areas of the current branch (Hall, Rooftop, Family). */
class AreaController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'areas.restore');
        $search = trim((string) $request->query('search'));

        $areas = $this->applyTab(Area::query(), $tab)
            ->withCount('tables')
            ->withSum('tables', 'capacity')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->ordered()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('areas/Index', [
            'areas' => AreaResource::collection($areas),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(Area::class),
        ]);
    }

    public function store(AreaRequest $request): RedirectResponse
    {
        $area = Area::create($request->areaData());

        return back()->with('success', "Area “{$area->name}” added.");
    }

    public function update(AreaRequest $request, Area $area): RedirectResponse
    {
        $area->update($request->areaData());

        return back()->with('success', "Area “{$area->name}” saved.");
    }

    public function destroy(Request $request, Area $area): RedirectResponse
    {
        $area->trash($this->trashReason($request));

        return back()->with('success', "Area “{$area->name}” moved to trash.");
    }

    public function restore(Area $area): RedirectResponse
    {
        $area->restoreFromTrash();

        return back()->with('success', "Area “{$area->name}” restored.");
    }
}
