<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\KitchenStationRequest;
use App\Http\Resources\KitchenStationResource;
use App\Models\KitchenStation;
use App\Support\MenuOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Kitchen stations of the current branch (KDS screen and/or KOT printer). */
class KitchenStationController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'kitchen-stations.restore');
        $search = trim((string) $request->query('search'));

        $stations = $this->applyTab(KitchenStation::query(), $tab)
            ->with('printer')
            ->withCount(['categories', 'menuItems', 'readyItems'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('kitchen-stations/Index', [
            'stations' => KitchenStationResource::collection($stations),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(KitchenStation::class),
            'printers' => MenuOptions::kitchenPrinters(),
        ]);
    }

    public function store(KitchenStationRequest $request): RedirectResponse
    {
        $station = KitchenStation::create($request->stationData());

        return back()->with('success', "Kitchen station “{$station->name}” added.");
    }

    public function update(KitchenStationRequest $request, KitchenStation $kitchenStation): RedirectResponse
    {
        $kitchenStation->update($request->stationData());

        return back()->with('success', "Kitchen station “{$kitchenStation->name}” saved.");
    }

    public function destroy(Request $request, KitchenStation $kitchenStation): RedirectResponse
    {
        $kitchenStation->trash($this->trashReason($request));

        return back()->with('success', "Kitchen station “{$kitchenStation->name}” moved to trash.");
    }

    public function restore(KitchenStation $kitchenStation): RedirectResponse
    {
        $kitchenStation->restoreFromTrash();

        return back()->with('success', "Kitchen station “{$kitchenStation->name}” restored.");
    }
}
